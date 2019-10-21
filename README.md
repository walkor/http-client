# Http client
Asynchronous http client for PHP based on workerman.

-  Asynchronous requests.

-  Uses PSR-7 interfaces for requests, responses.
   
-  Build-in connection pool.

# Installation
`composer require workerman/http-client`

# Examples
**example.php**
```php
<?php
require __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
$worker = new Worker();
$worker->onWorkerStart = function(){
    $http = new Workerman\Http\Client();
    
    $http->get('http://example.com/', function($response){
            var_dump($response->getStatusCode());
            echo $response->getBody();
        }, function($exception){
            echo $exception;
        });
    
    $http->post('http://example.com/', ['key1'=>'value1','key2'=>'value2'], function($response){
            var_dump($response->getStatusCode());
            echo $response->getBody();
        }, function($exception){
            echo $exception;
        });
    
    $http->request('http://example.com/', [
            'method'  => 'POST',
            'version' => '1.1',
            'headers' => ['Connection' => 'keep-alive'],
            'data'    => ['key1' => 'value1', 'key2'=>'value2'],
            'success' => function ($response) {
                echo $response->getBody();
            },
            'error'   => function ($exception) {
                echo $exception;
            }
        ]);
};
Worker::runAll();
```

Run with commands `php example.php start` or `php example.php start -d`

# License

MIT






