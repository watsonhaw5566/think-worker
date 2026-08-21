<?php

namespace think\worker\websocket\socketio;

class EnginePacket
{
    /**
     * Engine.io packet type `open`.
     */
    public const OPEN = 0;

    /**
     * Engine.io packet type `close`.
     */
    public const CLOSE = 1;

    /**
     * Engine.io packet type `ping`.
     */
    public const PING = 2;

    /**
     * Engine.io packet type `pong`.
     */
    public const PONG = 3;

    /**
     * Engine.io packet type `message`.
     */
    public const MESSAGE = 4;

    /**
     * Engine.io packet type 'upgrade'
     */
    public const UPGRADE = 5;

    /**
     * Engine.io packet type `noop`.
     */
    public const NOOP = 6;

    public $type;

    public $data = '';

    public function __construct($type, $data = '')
    {
        $this->type = $type;
        $this->data = $data;
    }

    public static function open($payload)
    {
        return new self(self::OPEN, $payload);
    }

    public static function pong($payload = '')
    {
        return new self(self::PONG, $payload);
    }

    public static function ping()
    {
        return new self(self::PING);
    }

    public static function message($payload)
    {
        return new self(self::MESSAGE, $payload);
    }

    public static function fromString(string $packet)
    {
        return new self(substr($packet, 0, 1), substr($packet, 1) ?: '');
    }

    public function toString()
    {
        return $this->type . $this->data;
    }
}
