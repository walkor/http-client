<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Http;


/**
 * Class Http\Client
 * @package Workerman\Http
 */
class Client
{
    const VERSION = '0.1.6';

    /**
     *
     *[
     *   address=>[
     *        [
     *        'url'=>x,
     *        'address'=>x
     *        'options'=>['method', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     *        ],
     *        ..
     *   ],
     *   ..
     * ]
     * @var array
     */
    protected $_queue = array();

    /**
     * @var array
     */
    protected $_connectionPool = null;

    /**
     * Client constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->_connectionPool = new ConnectionPool($options);
        $this->_connectionPool->on('idle', array($this, 'process'));
    }

    /**
     * Request.
     *
     * @param $url string
     * @param array $options ['method'=>'get', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     * @return void
     */
    public function request($url, $options = [])
    {
        $address = $this->parseAddress($url, $options);
        $options['url'] = $url;
        $this->queuePush($address, ['url' => $url, 'address' => $address, 'options' => $options]);
        $this->process($address);
    }

    /**
     * Get.
     *
     * @param $url
     * @param null $success_callback
     * @param null $error_callback
     */
    public function get($url, $success_callback = null, $error_callback = null)
    {
        $options = [];
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        $address = $this->parseAddress($url, $options);
        $task = [
            'url'      => $url,
            'options'  => $options,
            'address'  => $address,
        ];
        $this->queuePush($address, $task);
        $this->process($address);
    }

    /**
     * Post.
     *
     * @param $url
     * @param $data
     * @param null $success_callback
     * @param null $error_callback
     * @return bool
     */
    public function post($url, $data = [], $success_callback = null, $error_callback = null)
    {
        $options = [];
        if ($data) {
            $options['data'] = $data;
        }
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        $options['method'] = 'POST';
        $address = $this->parseAddress($url, $options);
        $task = [
            'url'      => $url,
            'options'  => $options,
            'address'  => $address
        ];
        $this->queuePush($address, $task);
        $this->process($address);
    }

    /**
     * Process.
     * User should not call this.
     *
     * @return void
     */
    public function process($address)
    {
        $task = $this->queueCurrent($address);
        if (!$task) {
            return;
        }

        $url = $task['url'];
        $address = $task['address'];
        $connection = $this->_connectionPool->fetch($address, strpos($url, 'https') === 0);

        // No connection is in idle state then wait.
        if (!$connection) {
            return;
        }

        $this->queuePop($address);
        $options = $task['options'];
        $request = new Request($url);
        $data = isset($options['data']) ? $options['data'] : '';
        if ($data || $data === '0' || $data === 0) {
            if (isset($options['method']) && (strtoupper($options['method']) === 'POST' || strtoupper($options['method']) === 'PUT' || strtoupper($options['method']) === 'PATCH')) {
                $request->write($options['data']);
            } else {
                $options['query'] = $data;
            }
        }
        $request->setOptions($options)->attachConnection($connection);

        $client = $this;
        $request->on('success', function($response) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request, $response);
            try {
                $new_request = Request::redirect($request, $response);
            } catch (\Exception $exception) {
                if (!empty($task['options']['error'])) {
                    call_user_func($task['options']['error'], $exception);
                }
                return;
            }
            // No redirect.
            if (!$new_request) {
                if (!empty($task['options']['success'])) {
                    call_user_func($task['options']['success'], $response);
                }
                return;
            }

            // Redirect.
            $uri = $new_request->getUri();
            $url = (string)$uri;
            $options = $new_request->getOptions();
            $address = $this->parseAddress($url, $options);
            $task = [
                'url'      => $url,
                'options'  => $options,
                'address'  => $address
            ];
            $this->queueUnshift($address, $task);
            $this->process($address);
        })->on('error', function($exception) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request);
            if (!empty($task['options']['error'])) {
                call_user_func($task['options']['error'], $exception);
            }
        });

        $state = $connection->getStatus(false);
        if ($state === 'CLOSING' || $state === 'CLOSED') {
            $connection->reconnect();
        }

        $state = $connection->getStatus(false);
        if ($state === 'CLOSED' || $state === 'CLOSING') {
            return;
        }

        $request->end('');
    }

    /**
     * Recycle connection from request.
     *
     * @param $request Request
     * @param $response Response
     */
    public function recycleConnectionFromRequest($request, $response = null)
    {
        $connection = $request->getConnection();
        if (!$connection) {
            return;
        }
        $connection->onConnect = $connection->onClose = $connection->onMessage = $connection->onError = null;
        $request_header_connection = strtolower($request->getHeaderLine('Connection'));
        $response_header_connection = $response ? strtolower($response->getHeaderLine('Connection')) : '';
        // Close Connection without header Connection: keep-alive
        if ('keep-alive' !== $request_header_connection || 'keep-alive' !== $response_header_connection || $request->getProtocolVersion() !== '1.1') {
            $connection->close();
        }
        $request->detachConnection($connection);
        $this->_connectionPool->recycle($connection);
    }

    /**
     * Parse address from url.
     *
     * @param $url
     * @param $options
     * @return string
     */
    protected function parseAddress($url, $options)
    {
        $info = parse_url($url);
        if (empty($info) || !isset($info['host'])) {
            $e = new \Exception("invalid url: $url");
            if (!empty($options['error'])) {
                call_user_func($options['error'], $e);
            }
        }
        $port = isset($info['port']) ? $info['port'] : (strpos($url, 'https') === 0 ? 443 : 80);
        return "tcp://{$info['host']}:{$port}";
    }

    /**
     * Queue push.
     *
     * @param $address
     * @param $task
     */
    protected function queuePush($address, $task)
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address][] = $task;
    }

    /**
     * Queue unshift.
     *
     * @param $address
     * @param $task
     */
    protected function queueUnshift($address, $task)
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address] += [$task];
    }

    /**
     * Queue current item.
     *
     * @param $address
     * @return mixed|null
     */
    protected function queueCurrent($address)
    {
        if (empty($this->_queue[$address])) {
            return null;
        }
        reset($this->_queue[$address]);
        return current($this->_queue[$address]);
    }

    /**
     * Queue pop.
     *
     * @param $address
     */
    protected function queuePop($address)
    {
        unset($this->_queue[$address][key($this->_queue[$address])]);
        if (empty($this->_queue[$address])) {
            unset($this->_queue[$address]);
        }
    }
}
