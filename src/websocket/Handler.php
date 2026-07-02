<?php

namespace think\worker\websocket;

use think\Config;
use think\Event;
use think\Request;
use think\worker\contract\websocket\HandlerInterface;
use think\worker\Websocket;
use think\worker\websocket\Event as WsEvent;
use Throwable;
use Workerman\Timer;

class Handler implements HandlerInterface
{
    protected $event;

    protected $config;

    protected $websocket;

    /** 心跳间隔（秒），服务端向客户端发送 ping，0 为禁用 */
    protected $pingInterval;

    /** 心跳超时（秒），超时未收到消息则关闭连接，0 为禁用 */
    protected $pingTimeout;

    protected $pingIntervalTimer = 0;
    protected $pingTimeoutTimer  = 0;

    public function __construct(Event $event, Config $config, Websocket $websocket)
    {
        $this->event        = $event;
        $this->config       = $config;
        $this->websocket    = $websocket;
        $this->pingInterval = $this->config->get('worker.websocket.ping_interval', 25);
        $this->pingTimeout  = $this->config->get('worker.websocket.ping_timeout', 60);
    }

    /**
     * "onOpen" listener.
     *
     * @param Request $request
     */
    public function onOpen(Request $request)
    {
        $this->event->trigger('worker.websocket.Open', $request);

        if ($this->pingInterval > 0) {
            $this->schedulePing();
        }
        if ($this->pingTimeout > 0) {
            $this->resetPingTimeout();
        }
    }

    /**
     * "onMessage" listener.
     *
     * @param Frame $frame
     */
    public function onMessage(Frame $frame)
    {
        if ($this->pingTimeout > 0) {
            $this->resetPingTimeout();
        }

        try {
            $this->event->trigger('worker.websocket.Message', $frame);
        } catch (Throwable) {
            // Message event errors should not break the connection
        }

        $event = $this->decode($frame->data);
        if ($event instanceof WsEvent) {
            if ($event->type === 'pong') {
                return;
            }
            try {
                $this->event->trigger('worker.websocket.Event', $event);
            } catch (Throwable) {
                // Event handler errors should not break the connection
            }
        }
    }

    /**
     * "onClose" listener.
     */
    public function onClose()
    {
        Timer::del($this->pingIntervalTimer);
        $this->pingIntervalTimer = 0;
        Timer::del($this->pingTimeoutTimer);
        $this->pingTimeoutTimer = 0;

        try {
            $this->event->trigger('worker.websocket.Close');
        } catch (Throwable) {
            // Close event errors should not affect cleanup
        }
    }

    /**
     * 启动下一次 ping 定时器
     */
    protected function schedulePing()
    {
        Timer::del($this->pingIntervalTimer);
        $this->pingIntervalTimer = Timer::delay($this->pingInterval, function () {
            try {
                $this->websocket->push(json_encode(['type' => 'ping', 'data' => time()]));
                $this->schedulePing();
            } catch (Throwable) {
                // Ping failure may indicate connection closed; stop rescheduling
            }
        });
    }

    /**
     * 重置心跳超时定时器
     */
    protected function resetPingTimeout()
    {
        Timer::del($this->pingTimeoutTimer);
        $this->pingTimeoutTimer = Timer::delay($this->pingTimeout, function () {
            try {
                $this->websocket->close();
            } catch (Throwable) {
                // Connection may already be closed
            }
        });
    }

    protected function decode($payload)
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $data = json_decode($payload, true);
        if (is_array($data) && !empty($data['type']) && is_string($data['type'])) {
            return new WsEvent($data['type'], $data['data'] ?? null);
        }
        return null;
    }

    public function encodeMessage($message)
    {
        if ($message instanceof WsEvent) {
            return json_encode([
                'type' => $message->type,
                'data' => $message->data,
            ]);
        }
        return $message;
    }
}