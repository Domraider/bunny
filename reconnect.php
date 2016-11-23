<?php

require "vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$mq = new \Bunny\Async\Client($loop, [
    'host' => '127.0.0.1',
    'port' => 5672,
    'user' => 'guest',
    'password' => 'guest',
    //'heartbeat' => 6,
    //'timeout'=>2.0
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
        return $mq->channel();
    })
    ->subscribeCallback(function (\Bunny\Channel $channel) use ($mq, $loop) {
        echo "connected\n";
        \Rx\Observable::interval(2000)
            // <- here we drop during disconnect disconnect
            // <- add a buffer ? include it on produce ?
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

// Publish every 1s
$loop->run();