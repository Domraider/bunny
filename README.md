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

$mq->connect()
 ->doOnError(function(\Exception $e) {
     echo "Disconnected, retry in 2s {$e->getMessage()}\n";
 })
 ->retryWhen(function($errors) {
     return $errors->delay(2000);
 })
 ->subscribeCallback(function () {
     echo "connected";
 }, function () {
     echo "error";
 }, null, new \Rx\Scheduler\EventLoopScheduler($loop));

Rx\Observable::interval(4000)
 ->flatMap(function() use($mq) {
     return $mq->channel();
 })
 ->flatMap(function (\Bunny\Channel $channel) use ($mq) {
     echo "produce message\n";
     return \Rxnet\fromPromise($channel->publish('test', [], 'amq.direct', 'test'));
 })
 ->retryWhen(function($errors) {
     return $errors->delay(2000);
 })
 ->subscribeCallback(
     function () {
         echo "  OK\n";
     },
     function (\Exception $e) {
         echo "  {$e->getMessage()}\n";
         $trace = $e->getTrace();
         var_dump($trace[0]);
     }, null,
     new \Rx\Scheduler\EventLoopScheduler($loop)
 );

```

> Performant pure-PHP AMQP (RabbitMQ) sync/async (ReactPHP) library


