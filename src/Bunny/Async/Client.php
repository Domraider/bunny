<?php
namespace Bunny\Async;

use Bunny\AbstractClient;
use Bunny\Channel;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol\HeartbeatFrame;
use Bunny\Protocol\MethodConnectionStartFrame;
use Bunny\Protocol\MethodConnectionTuneFrame;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\Promise;
use Rx\Observable;
use Rx\ObserverInterface;

/**
 * Asynchronous AMQP/RabbitMQ client. Uses ReactPHP's event loop.
 *
 * The client's API follows AMQP class/method naming convention and uses PHP's idiomatic camelCase method naming
 * convention - e.g. "queue.declare" has corresponding method "queueDeclare", "exchange.delete" -> "exchangeDelete".
 * Methods from "basic" class are not prefixed with "basic" - e.g. "basic.publish" is just "publish".
 *
 * Usage:
 *
 *     $c = new Bunny\Async\Client([
 *         "host" => "127.0.0.1",
 *         "port" => 5672,
 *         "vhost" => "/",
 *         "user" => "guest",
 *         "password" => "guest",
 *     ]);
 *
 *     // $c->connect() returns React\Promise\PromiseInterface
 *
 *     $c->connect()->then(function(Bunny\Async\Client $c) {
 *         // work with connected client
 *
 *     }, function ($e) {
 *         // an exception occurred
 *     });
 *
 * @author Jakub Kulhan <jakub.kulhan@gmail.com>
 */
class Client extends AbstractClient
{

    /** @var LoopInterface */
    protected $eventLoop;

    /** @var Promise\PromiseInterface */
    protected $flushWriteBufferPromise;

    /** @var callable[] */
    protected $awaitCallbacks;

    /** @var Timer */
    protected $stopTimer;

    /** @var Timer */
    protected $heartbeatTimer;

    /**
     * Constructor.
     *
     * @param LoopInterface $eventLoop
     * @param array $options see {@link AbstractClient} for available options
     * @param LoggerInterface $log if argument is passed, AMQP communication will be recorded in debug level
     */
    public function __construct(LoopInterface $eventLoop = null, array $options = [], LoggerInterface $log = null)
    {
        $options["async"] = true;
        parent::__construct($options, $log);

        if ($eventLoop === null) {
            $eventLoop = Factory::create();
        }

        $this->eventLoop = $eventLoop;
    }

    /**
     * Destructor.
     *
     * Clean shutdown = disconnect if connected.
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }
    }

    /**
     * Initializes instance.
     */
    protected function init()
    {
        parent::init();
        $this->flushWriteBufferPromise = null;
        $this->awaitCallbacks = [];
        $this->disconnectPromise = null;
        if($this->heartbeatTimer instanceof Timer) {
            $this->heartbeatTimer->cancel();
        }
    }

    /**
     * Calls {@link eventLoop}'s run() method. Processes messages for at most $maxSeconds.
     *
     * @param float $maxSeconds
     */
    public function run($maxSeconds = null)
    {
        if ($maxSeconds !== null) {
            $this->stopTimer = $this->eventLoop->addTimer($maxSeconds, function () {
                $this->stop();
            });
        }

        $this->eventLoop->run();
    }

    /**
     * Calls {@link eventLoop}'s stop() method.
     */
    public function stop()
    {
        if ($this->stopTimer) {
            $this->stopTimer->cancel();
            $this->stopTimer = null;
        }

        $this->eventLoop->stop();
    }

    /**
     * Reads data from stream to read buffer.
     */
    protected function feedReadBuffer()
    {
        throw new \LogicException("feedReadBuffer() in async client does not make sense.");
    }

    /**
     * Asynchronously sends buffered data over the wire.
     *
     * - Calls {@link eventLoops}'s addWriteStream() with client's stream.
     * - Consecutive calls will return the same instance of promise.
     *
     * @return Promise\PromiseInterface
     */
    protected function flushWriteBuffer()
    {
        if ($this->flushWriteBufferPromise) {
            return $this->flushWriteBufferPromise;

        } else {
            $deferred = new Promise\Deferred();
            try {
                $stream = $this->getStream();
            } catch (\Exception $e) {
                //echo "Write can't have stream {$e->getMessage()}\n";
                return $deferred->reject($e);
            }
            $this->eventLoop->addWriteStream($stream, function ($stream) use ($deferred) {
                try {
                    $this->write();

                    if ($this->writeBuffer->isEmpty()) {
                        $this->eventLoop->removeWriteStream($stream);
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }

                } catch (\Exception $e) {
                    $this->eventLoop->removeWriteStream($stream);
                    $this->flushWriteBufferPromise = null;
                    $deferred->reject($e);
                }
            });

            return $this->flushWriteBufferPromise = $deferred->promise();
        }
    }

    public function channel()
    {
        for ($channelId = 1; isset($this->channels[$channelId]); ++$channelId) ;
        $this->channels[$channelId] = new Channel($this, $channelId);

        return $this->connect()
            ->flatMap(function ($mq) use ($channelId) {
                return \Rxnet\fromPromise($mq->channelOpen($channelId));
            })
            ->map(function () use ($channelId) {
                return $this->channels[$channelId];
            });


    }

    /**
     * @return Observable
     */
    public function connect()
    {
        if ($this->state == ClientStateEnum::CONNECTED) {
            return Observable::just($this);
        }

        return Observable::create(function (ObserverInterface $observer) {
            $this->state = ClientStateEnum::CONNECTING;
            $this->writer->appendProtocolHeader($this->writeBuffer);
            //echo 'try to connect';
            try {
                $stream = $this->getStream();
            } catch (\Exception $e) {
                $this->state = ClientStateEnum::ERROR;
                $this->closeStream();
                $this->init();
                $observer->onError($e);
                return;
            }

            $this->eventLoop->addReadStream($stream, function ($stream) use ($observer) {
                try {
                    $this->read();
                } catch (\Exception $e) {
                    $this->state = ClientStateEnum::ERROR;
                    $this->eventLoop->removeReadStream($stream);
                    $this->closeStream();
                    $this->init();

                    $observer->onError($e);
                    return;
                }

                while (($frame = $this->reader->consumeFrame($this->readBuffer)) !== null) {
                    foreach ($this->awaitCallbacks as $k => $callback) {
                        if ($callback($frame) === true) {
                            unset($this->awaitCallbacks[$k]);
                            continue 2; // CONTINUE WHILE LOOP
                        }
                    }

                    if ($frame->channel === 0) {
                        $this->onFrameReceived($frame);

                    } else {
                        if (!isset($this->channels[$frame->channel])) {
                            throw new ClientException(
                                "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                            );
                        }

                        $this->channels[$frame->channel]->onFrameReceived($frame);
                    }
                }
            });

            $this->flushWriteBuffer()->then(function () {
                return $this->awaitConnectionStart();

            })->then(function (MethodConnectionStartFrame $start) {
                return $this->authResponse($start);

            })->then(function () {
                return $this->awaitConnectionTune();

            })->then(function (MethodConnectionTuneFrame $tune) {
                $this->frameMax = $tune->frameMax;
                return $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->options["heartbeat"]);

            })->then(function () {
                return $this->connectionOpen($this->options["vhost"]);

            })->then(function () {
                $this->heartbeatTimer = $this->eventLoop->addTimer($this->options["heartbeat"], [$this, "onHeartbeat"]);

                $this->state = ClientStateEnum::CONNECTED;
                return $this;

            })->then(function ($client) use ($observer) {
                $observer->onNext($client);
            });
        });


    }

    /**
     * Disconnects client from server.
     *
     * - Calling disconnect() if client is not connected will result in error.
     * - Calling disconnect() multiple times will result in the same promise.
     *
     * @param int $replyCode
     * @param string $replyText
     * @return Promise\PromiseInterface
     */
    public function disconnect($replyCode = 0, $replyText = "")
    {
        if ($this->state === ClientStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }

        if ($this->state !== ClientStateEnum::CONNECTED) {
            return Promise\reject(new ClientException("Client is not connected."));
        }

        $this->state = ClientStateEnum::DISCONNECTING;

        $promises = [];

        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }

        if ($this->heartbeatTimer) {
            $this->heartbeatTimer->cancel();
            $this->heartbeatTimer = null;
        }

        return $this->disconnectPromise = Promise\all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException("All channels have to be closed by now.");
            }

            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () {
            $this->eventLoop->removeReadStream($this->getStream());
            $this->closeStream();
            $this->init();
            return $this;
        });
    }

    /**
     * Creates stream according to options passed in constructor.
     *
     * @return resource
     */
    protected function getStream()
    {
        if ($this->stream === null) {
            // TODO: SSL
            // see https://github.com/nrk/predis/blob/v1.0/src/Connection/StreamConnection.php
            $uri = "tcp://{$this->options["host"]}:{$this->options["port"]}";
            $flags = STREAM_CLIENT_CONNECT;

            if (isset($this->options["async_connect"]) && !!$this->options["async_connect"]) {
                $flags |= STREAM_CLIENT_ASYNC_CONNECT;
            }

            if (isset($this->options["persistent"]) && !!$this->options["persistent"]) {
                $flags |= STREAM_CLIENT_PERSISTENT;

                if (!isset($this->options["path"])) {
                    throw new ClientException("If you need persistent connection, you have to specify 'path' option.");
                }

                $uri .= (strpos($this->options["path"], "/") === 0) ? $this->options["path"] : "/" . $this->options["path"];
            }

            $this->stream = stream_socket_client($uri, $errno, $errstr, (float)$this->options["timeout"], $flags);

            if (!$this->stream) {
                throw new ClientException(
                    "Could not connect to {$this->options["host"]}:{$this->options["port"]}: {$errstr}.",
                    $errno
                );
            }

            if (isset($this->options["read_write_timeout"])) {
                $readWriteTimeout = (float)$this->options["read_write_timeout"];
                if ($readWriteTimeout < 0) {
                    $readWriteTimeout = -1;
                }
                $readWriteTimeoutSeconds = floor($readWriteTimeout);
                $readWriteTimeoutMicroseconds = ($readWriteTimeout - $readWriteTimeoutSeconds) * 10e6;
                stream_set_timeout($this->stream, $readWriteTimeoutSeconds, $readWriteTimeoutMicroseconds);
            }

            if (isset($this->options["tcp_nodelay"]) && function_exists("socket_import_stream")) {
                $socket = socket_import_stream($this->stream);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, (int)$this->options["tcp_nodelay"]);
            }

            if ($this->options["async"]) {
                stream_set_blocking($this->stream, 0);
            }
        }
        return $this->stream;
    }

    /**
     * Adds callback to process incoming frames.
     *
     * Callback is passed instance of {@link \Bunny\Protocol|AbstractFrame}. If callback returns TRUE, frame is said to
     * be handled and further handlers (other await callbacks, default handler) won't be called.
     *
     * @param callable $callback
     */
    public function addAwaitCallback(callable $callback)
    {
        $this->awaitCallbacks[] = $callback;
    }

    /**
     * {@link eventLoop}'s read stream callback notifying client that data from server arrived.
     */
    public function onDataAvailable()
    {
        $this->read();

        while (($frame = $this->reader->consumeFrame($this->readBuffer)) !== null) {
            foreach ($this->awaitCallbacks as $k => $callback) {
                if ($callback($frame) === true) {
                    unset($this->awaitCallbacks[$k]);
                    continue 2; // CONTINUE WHILE LOOP
                }
            }

            if ($frame->channel === 0) {
                $this->onFrameReceived($frame);

            } else {
                if (!isset($this->channels[$frame->channel])) {
                    throw new ClientException(
                        "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                    );
                }

                $this->channels[$frame->channel]->onFrameReceived($frame);
            }
        }
    }

    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat()
    {
        $now = microtime(true);
        $nextHeartbeat = ($this->lastWrite ?: $now) + $this->options["heartbeat"];
        if($this->getState() != ClientStateEnum::CONNECTED) {
            $this->heartbeatTimer = $this->eventLoop->addTimer($this->options["heartbeat"], [$this, "onHeartbeat"]);
            return;
        }

        if ($now >= $nextHeartbeat) {
            $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
            $this->flushWriteBuffer()->done(function () {
                $this->heartbeatTimer = $this->eventLoop->addTimer($this->options["heartbeat"], [$this, "onHeartbeat"]);
            });

        } else {
            $this->heartbeatTimer = $this->eventLoop->addTimer($nextHeartbeat - $now, [$this, "onHeartbeat"]);
        }
    }

}
