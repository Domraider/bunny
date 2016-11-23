# BunnyPHP

Fork to handle automatic reconnect : 

```php
require "vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$mq = new \Bunny\Async\Client($loop, [
    'host' => '127.0.0.1',
    'port' => 5672,
    'user' => 'guest',
    'password' => 'guest',
]);

// Connect is refactored to be retryable
$mq->connect()
    ->retryWhen(function ($errors) {
        // Retry to connect on error
        return $errors->delay(2000)
            ->doOnNext(function () {
                echo "Disconnected, retry\n";
            });
    })
    ->flatMap(function () use ($mq) {
        // Open only 1 channel
        return $mq->channel();
    })
    ->subscribeCallback(function (\Bunny\Channel $channel) use ($mq, $loop) {
        echo "connected\n";
        // Publish every 2s
        \Rx\Observable::interval(2000)
            // <- here we drop during disconnect
            // <- You have to add a buffer
            ->flatMap(function () use ($mq, $channel) {
                $data = md5(microtime(true));
                echo "Produce {$data}\n";
                return \Rx\React\Promise::toObservable($channel->publish($data, [], 'amq.direct', 'test'))
                    ->map(function () use ($data) {
                        return $data;
                    });

            })
            ->subscribeCallback(
                function ($data) {
                    echo "  {$data} : OK\n";
                },
                function () {
                    echo "error during produce\n";
                }, null,
                new \Rx\Scheduler\EventLoopScheduler($loop)
            );
    }, function (\Exception $e) {
        echo "disconnected : {$e->getMessage()}\n";
    }, null, new \Rx\Scheduler\EventLoopScheduler($loop));


$loop->run();
```

> Performant pure-PHP AMQP (RabbitMQ) sync/async (ReactPHP) library


