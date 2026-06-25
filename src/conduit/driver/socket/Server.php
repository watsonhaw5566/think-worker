<?php

namespace think\worker\conduit\driver\socket;

use think\worker\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Frame;

class Server
{
    protected $data = [];

    /** @var array<string,TcpConnection[]> */
    protected $subscribers = [];

    public function onMessage(TcpConnection $connection, $buffer)
    {
        if (empty($buffer)) {
            return;
        }

        $command = json_decode($buffer, true);
        if (!is_array($command) || !isset($command['type']) || $command['type'] !== 'command' || !isset($command['name']) || !isset($command['key'])) {
            return;
        }

        $hasResult = isset($command['id']);
        $resultData = ['type' => 'result', 'id' => $command['id'], 'data' => null];

        switch ($command['name']) {
            case 'get':
                $resultData['data'] = $this->data[$command['key']] ?? null;
                break;
            case 'set':
                $this->data[$command['key']] = $command['data'];
                break;
            case 'inc':
                if (!isset($this->data[$command['key']]) || !is_integer($this->data[$command['key']])) {
                    $this->data[$command['key']] = 0;
                }
                $resultData['data'] = $this->data[$command['key']] += $command['data'] ?? 1;
                break;
            case 'sAdd':
                if (!isset($this->data[$command['key']]) || !is_array($this->data[$command['key']])) {
                    $this->data[$command['key']] = [];
                }
                $addValues = is_array($command['data']) ? $command['data'] : [];
                $this->data[$command['key']] = array_values(array_unique(array_merge($this->data[$command['key']], $addValues)));
                break;
            case 'sRem':
                if (!isset($this->data[$command['key']]) || !is_array($this->data[$command['key']])) {
                    $this->data[$command['key']] = [];
                }
                $removeValues = is_array($command['data']) ? $command['data'] : [$command['data']];
                $this->data[$command['key']] = array_values(array_diff($this->data[$command['key']], $removeValues));
                break;
            case 'sMembers':
                if (!isset($this->data[$command['key']]) || !is_array($this->data[$command['key']])) {
                    $this->data[$command['key']] = [];
                }
                $resultData['data'] = $this->data[$command['key']];
                break;
            case 'subscribe':
                if (!isset($this->subscribers[$command['key']])) {
                    $this->subscribers[$command['key']] = [];
                }
                $this->subscribers[$command['key']][] = $connection;
                break;
            case 'publish':
                if (!empty($this->subscribers[$command['key']])) {
                    $eventData = @json_encode([
                        'type' => 'event',
                        'name' => $command['key'],
                        'data' => $command['data'],
                    ]);
                    if ($eventData !== false) {
                        foreach ($this->subscribers[$command['key']] as $conn) {
                            $conn->send($eventData);
                        }
                    }
                }
                break;
        }

        if ($hasResult) {
            $response = @json_encode($resultData);
            if ($response !== false) {
                $connection->send($response);
            }
        }
    }

    public function onClose(TcpConnection $connection)
    {
        if (!empty($this->subscribers)) {
            foreach ($this->subscribers as $key => $connections) {
                $this->subscribers[$key] = array_udiff($connections, [$connection], function ($a, $b) {
                    return $a <=> $b;
                });
            }
        }
    }

    public static function run($domain)
    {
        //启动服务端
        $server = new self();

        $worker = new Worker($domain);

        $worker->name       = 'conduit';
        $worker->protocol   = Frame::class;
        $worker->reloadable = false;

        $worker->onMessage = [$server, 'onMessage'];
        $worker->onClose   = [$server, 'onClose'];
    }
}