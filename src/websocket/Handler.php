<?php

namespace think\worker\websocket;

use think\Config;
use think\Event;
use think\Request;
use think\worker\contract\websocket\HandlerInterface;
use think\worker\Websocket;
use think\worker\websocket\Event as WsEvent;
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
        // 收到任何消息都重置心跳超时
        if ($this->pingTimeout > 0) {
            $this->resetPingTimeout();
        }

        $this->event->trigger('worker.websocket.Message', $frame);

        $event = $this->decode($frame->data);
        if ($event) {
            // 收到 pong 响应，不触发业务事件
            if ($event->type === 'pong') {
                return;
            }
            $this->event->trigger('worker.websocket.Event', $event);
        }
    }

    /**
     * "onClose" listener.
     */
    public function onClose()
    {
        Timer::del($this->pingIntervalTimer);
        Timer::del($this->pingTimeoutTimer);
        $this->event->trigger('worker.websocket.Close');
    }

    /**
     * 启动下一次 ping 定时器
     */
    protected function schedulePing()
    {
        Timer::del($this->pingIntervalTimer);
        $this->pingIntervalTimer = Timer::delay($this->pingInterval, function () {
            $this->websocket->push(json_encode(['type' => 'ping', 'data' => time()]));
            $this->schedulePing();
        });
    }

    /**
     * 重置心跳超时定时器
     */
    protected function resetPingTimeout()
    {
        Timer::del($this->pingTimeoutTimer);
        $this->pingTimeoutTimer = Timer::delay($this->pingTimeout, function () {
            $this->websocket->close();
        });
    }

    protected function decode($payload)
    {
        $data = json_decode($payload, true);
        if (!empty($data['type'])) {
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