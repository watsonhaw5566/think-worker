<?php

namespace think\worker;

class Ipc
{
    protected $workerId;

    protected $allowedClasses = [
        message\PushMessage::class,
    ];

    public function __construct(protected Manager $manager, protected Conduit $conduit)
    {

    }

    public function listenMessage()
    {
        $this->subscribe();
        return $this->workerId;
    }

    public function sendMessage($workerId, $message)
    {
        if ($workerId === $this->workerId) {
            $this->manager->triggerEvent('message', $message);
        } else {
            $this->publish($workerId, $message);
        }
    }

    public function subscribe()
    {
        $this->workerId = $this->conduit->inc('ipc:worker');
        $this->conduit->subscribe("ipc:message:{$this->workerId}", function ($message) {
            $decoded = $this->decodeMessage($message);
            if ($decoded !== null) {
                $this->manager->triggerEvent('message', $decoded);
            }
        });
    }

    public function publish($workerId, $message)
    {
        $encoded = $this->encodeMessage($message);
        if ($encoded !== null) {
            $this->conduit->publish("ipc:message:{$workerId}", $encoded);
        }
    }

    protected function encodeMessage($message)
    {
        if (is_object($message)) {
            $class = get_class($message);
            if (!in_array($class, $this->allowedClasses, true)) {
                return null;
            }
            $payload = [
                '_c' => $class,
                '_d' => (array) $message,
            ];
        } else {
            $payload = [
                '_c' => null,
                '_d' => $message,
            ];
        }

        $json = @json_encode($payload);
        return $json !== false ? $json : null;
    }

    protected function decodeMessage($encoded)
    {
        $payload = json_decode($encoded, true);
        if (!is_array($payload) || !array_key_exists('_d', $payload)) {
            return null;
        }

        if (!empty($payload['_c']) && is_string($payload['_c']) && in_array($payload['_c'], $this->allowedClasses, true)) {
            $reflection = new \ReflectionClass($payload['_c']);
            $instance = $reflection->newInstanceWithoutConstructor();
            foreach ($payload['_d'] as $key => $value) {
                if (property_exists($instance, $key)) {
                    $instance->$key = $value;
                }
            }
            return $instance;
        }

        return $payload['_d'];
    }
}