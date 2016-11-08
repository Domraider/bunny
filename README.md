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

## Requirements

BunnyPHP requires PHP `>= 5.4.0`.

## Installation

Add as [Composer](https://getcomposer.org/) dependency:

```sh
$ composer require bunny/bunny:@dev
```

## Comparison

You might ask if there isn't a library/extension to connect to AMQP broker (e.g. RabbitMQ) already. Yes, there are
 multiple options:

- [ext-amqp](http://pecl.php.net/package/amqp) - PHP extension
- [php-amqplib](https://github.com/php-amqplib/php-amqplib) - pure-PHP AMQP protocol implementation
- [react-amqp](https://github.com/JCook21/ReactAMQP) - ext-amqp binding to ReactPHP

Why should you want to choose BunnyPHP instead?

* You want **nice idiomatic PHP API** to work with (I'm looking at you, php-amqplib). BunnyPHP interface follows PHP's common
  **coding standards** and **naming conventions**. See tutorial.

* You **can't (don't want to) install PECL extension** that has latest stable version in 2014. BunnyPHP isn't as such marked
  as stable yet. But it is already being used in production.

* You have **both classic CLI/FPM and [ReactPHP](http://reactphp.org/)** applications and need to connect to RabbitMQ.
  BunnyPHP comes with both **synchronous and asynchronous** clients with same PHP-idiomatic interface. Async client uses
  [react/promise](https://github.com/reactphp/promise).

Apart from that BunnyPHP is more performant than main competing library, php-amqplib. See [`benchmark/` directory](https://github.com/jakubkulhan/bunny/tree/master/benchmark)
and [php-amqplib's `benchmark/`](https://github.com/videlalvaro/php-amqplib/tree/master/benchmark).

Benchmarks were run as:

```sh
$ php benchmark/producer.php N & php benchmark/consumer.php
```

| Library     | N (# messages) | Produce sec | Produce msg/sec | Consume sec | Consume msg/sec |
|-------------|---------------:|------------:|----------------:|------------:|----------------:|
| php-amqplib | 100            | 0.0131      | 7633            | 0.0446      | 2242            |
| bunnyphp    | 100            | 0.0128      | 7812            | 0.0488      | 2049            |
| bunnyphp +/-|                |             | +2.3%           |             | -8.6%           |
| php-amqplib | 1000           | 0.1218      | 8210            | 0.4801      | 2082            |
| bunnyphp    | 1000           | 0.1042      | 9596            | 0.2919      | 3425            |
| bunnyphp +/-|                |             | +17%            |             | +64%            |
| php-amqplib | 10000          | 1.1075      | 9029            | 5.1824      | 1929            |
| bunnyphp    | 10000          | 0.9078      | 11015           | 2.9058      | 3441            |
| bunnyphp +/-|                |             | +22%            |             | +78%            |
| php-amqplib | 100000         | 20.7005     | 4830            | 69.0360     | 1448            |
| bunnyphp    | 100000         | 9.7891      | 10215           | 35.7305     | 2789            |
| bunnyphp +/-|                |             | +111%           |             | +92%            |

## Tutorial

### Connecting

When instantiating the BunnyPHP `Client` accepts an array with connection options:

```php
$connection = [
    'host'      => 'HOSTNAME',
    'vhost'     => 'VHOST',    // The default vhost is /
    'user'      => 'USERNAME', // The default user is guest
    'password'  => 'PASSWORD', // The default password is guest
];

$bunny = new Client($connection);
$bunny->connect();
```

### Publish a message

Now that we have a connection with the server we need to create a channel and declare a queue to communicate over before we can publish a message, or subscribe to a queue for that matter.

```php
$channel = $bunny->channel();
$channel->queueDeclare('queue_name'); // Queue name
```

With a communication channel set up, we can now publish a message to the queue:

```php
$channel->publish(
    $message,    // The message you're publishing as a string
    [],          // Any headers you want to add to the message
    '',          // Exchange name
    'queue_name' // Routing key, in this example the queue's name
);
```

### Subscribing to a queue

Subscribing to a queue can be done in two ways. The first way will run indefinitely:

```php
$channel->run(
    function (Message $message, Channel $channel, Client $bunny) {
        $success = handleMessage($message); // Handle your message here

        if ($success) {
            $channel->ack($message); // Acknowledge message
            return;
        }

        $channel->nack($message); // Mark message fail, message will be redelivered
    },
    'queue_name'
);
```

The other way lets you run the client for a specific amount of time consuming the queue before it stops:

```php
$channel->consume(
    function (Message $message, Channel $channel, Client $client){
        $channel->ack($message); // Acknowledge message
    },
    'queue_name'
);
$bunny->run(12); // Client runs for 12 seconds and then stops
```

