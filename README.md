# BunnyPHP

Fork to handle automatic reconnect : 

```php
$loop = \EventLoop\EventLoop::getLoop();
$mq = new \Bunny\Async\Client($loop, [
 'host' => '127.0.0.1', 
 'port' => 5672,
 'user' => 'guest',
 'password' => 'guest',
 'heartbeat' => 6,
 'timeout'=>2.0
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
    ->subscribeCallback(function () {
        echo "connected\n";
    }, null, null, new \Rx\Scheduler\EventLoopScheduler($loop));

// Publish every 1s
\Rx\Observable::interval(1000)
    ->flatMap(function () use ($mq) {
        // Publish must depends on channel opening success
        // Refactored to depends on connect
        return $mq->channel();
    })
    ->flatMap(function (\Bunny\Channel $channel) use ($mq) {
        $data = md5(microtime(true));
        echo "Produce {$data}\n";
        return \Rxnet\fromPromise($channel->publish($data, [], 'amq.direct', 'test'))
            ->doOnNext(function () use ($channel) {
               // Close channel when publish ok
               $channel->close();
            })->map(function () use ($data) {
                return $data;
            });

    })
    ->retryWhen(function ($errors) {
        // Retry indefinitely to publish
        return $errors->delay(2000)
            ->doOnNext(function () {
                echo "produce error, wait for connection to be up.\n";
            });
    })
    ->subscribeCallback(
        function ($data) {
            echo "  {$data} : OK\n";
        }, null, null,
        new \Rx\Scheduler\EventLoopScheduler($loop)
    );
```

> Performant pure-PHP AMQP (RabbitMQ) sync/async (ReactPHP) library


