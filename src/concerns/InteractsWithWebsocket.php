<?php

namespace think\worker\concerns;

use think\App;
use think\Event;
use think\helper\Arr;
use think\Http;
use think\worker\message\PushMessage;
use think\worker\Websocket;
use think\worker\contract\websocket\HandlerInterface;
use think\worker\websocket\Frame;
use think\worker\websocket\Handler;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkerRequest;
use Workerman\Protocols\Http\Response;

trait InteractsWithWebsocket
{

    /** @var array<int, callable> */
    protected array $messageSender = [];

    protected function prepareWebsocket()
    {
        $this->onEvent('workerStart', function () {
            $handlerClass = $this->getConfig('websocket.handler', Handler::class);
            $this->app->bind(HandlerInterface::class, $handlerClass);

            $this->onEvent('message', function ($message) {
                if ($message instanceof PushMessage) {
                    // Use WeakMap does not apply here; check closure holds the  Use array access with direct reference but I'm fixing the
                    if (isset($this->messageSender[$message->to])) {
                        try {
                            $this->messageSender[$message->to]($message->data);
                        } catch (Throwable) {
                            // Connection may have been closed; clean up stale entry
                            unset($this->messageSender[$message->to]);
                        }
                    }
                }
            });
        });
    }

    public function onHandShake(TcpConnection $connection, WorkerRequest $wkRequest)
    {
        $this->runInSandbox(function (App $app, Http $http, Event $event) use ($connection, $wkRequest) {
            $request = $this->prepareRequest($wkRequest);

            $response = $http->run($request);
            if (!$response instanceof \think\worker\response\Websocket) {
                $connection->close();
                return;
            }

            $event->subscribe([$response]);
            $this->upgrade($connection, $wkRequest);

            $websocket = $app->make(Websocket::class, [$connection], true);
            $app->instance(Websocket::class, $websocket);

            $id = "{$this->workerId}.{$connection->id}";

            $websocket->setSender($id);
            $websocket->join($id);

            $handler = $app->make(HandlerInterface::class);

            $this->messageSender[$connection->id] = function ($data) use ($connection, $handler) {
                $connection->send($handler->encodeMessage($data));
            };

            try {
                $handler->onOpen($request);
            } catch (Throwable $e) {
                $this->logServerError($e);
            }
        }, $connection);
    }

    public function onMessage(TcpConnection $connection, Frame $frame)
    {
        $this->runInSandbox(function (App $app) use ($frame) {
            $handler = $app->make(HandlerInterface::class);
            try {
                $handler->onMessage($frame);
            } catch (Throwable $e) {
                $this->logServerError($e);
            }
        }, $connection);
    }

    public function onClose(TcpConnection $connection)
    {
        // Always clean up the sender regardless of sandbox state
        unset($this->messageSender[$connection->id]);

        $this->runInSandbox(function (App $app) {
            if ($app->exists(Websocket::class)) {
                $websocket = $app->make(Websocket::class);
                try {
                    $handler = $app->make(HandlerInterface::class);
                    $handler->onClose();
                } catch (Throwable $e) {
                    $this->logServerError($e);
                }

                try {
                    $websocket->leave();
                } catch (Throwable) {
                    // Room cleanup may fail if conduit disconnected; ignore
                }

                $websocket->setConnected(false);
            }
        }, $connection);
    }

    protected function isWebsocketRequest(WorkerRequest $request)
    {
        $header = $request->header();
        $connection = strtolower(Arr::get($header, 'connection', ''));
        $connectionTokens = array_filter(array_map('trim', explode(',', $connection)));
        return in_array('upgrade', $connectionTokens, true) &&
            strcasecmp(Arr::get($header, 'upgrade', ''), 'websocket') === 0 &&
            !empty(Arr::get($header, 'sec-websocket-key', '')) &&
            Arr::get($header, 'sec-websocket-version', '') === '13';
    }

    protected function upgrade(TcpConnection $connection, WorkerRequest $request)
    {
        $key = $request->header('Sec-WebSocket-Key');

        $headers = [
            'Upgrade'               => 'websocket',
            'Sec-WebSocket-Version' => '13',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ];

        if ($protocol = $request->header('Sec-Websocket-Protocol')) {
            $headers['Sec-WebSocket-Protocol'] = $protocol;
        }

        $response = new Response(101, $headers);

        $connection->send($response);

        // Websocket data buffer.
        $connection->context->websocketDataBuffer = '';
        // Current websocket frame length.
        $connection->context->websocketCurrentFrameLength = 0;
        // Current websocket frame data.
        $connection->context->websocketCurrentFrameBuffer = '';

        $connection->context->websocketHandshake = true;
    }
}