<?php

namespace think\worker\conduit\driver;

use Exception;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use think\worker\conduit\Driver;
use think\worker\conduit\driver\socket\Command;
use think\worker\conduit\driver\socket\Event;
use think\worker\conduit\driver\socket\Result;
use think\worker\conduit\driver\socket\Server;
use think\worker\Manager;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Frame;
use Workerman\Timer;

class Socket extends Driver
{
    protected int $id = 0;
    protected string $domain;

    /** @var AsyncTcpConnection|null */
    protected ?AsyncTcpConnection $connection = null;
    protected mixed $reconnectTimer = null;
    protected int $reconnectAttempts = 0;
    protected int $maxReconnectAttempts = 30;
    protected int $reconnectBaseDelay = 1;
    protected int $pingInterval = 55;

    /** @var array<int, array{0: Suspension, 1: int}> */
    protected array $suspensions = [];
    protected array $events = [];

    public function __construct(protected Manager $manager)
    {
        $filename = runtime_path() . 'conduit.sock';
        @unlink($filename);
        $this->domain = "unix://{$filename}";
    }

    public function prepare()
    {
        Server::run($this->domain);
    }

    public function connect()
    {
        $suspension       = EventLoop::getSuspension();
        $this->connection = $this->createConnection($suspension);

        $timeoutAlarm = Timer::add(10, function () use ($suspension) {
            try {
                $suspension->throw(new Exception('conduit connection timed out'));
            } catch (Throwable) {
                // Suspension might already be resumed
            }
        }, [], false);

        try {
            $suspension->suspend();
        } finally {
            Timer::del($timeoutAlarm);
        }

        Timer::add($this->pingInterval, function () {
            if ($this->connection && $this->connection->getStatus()) {
                @$this->connection->send('');
            }
        });

        Timer::add(1, function () {
            $now     = time();
            $expired = [];
            foreach ($this->suspensions as $id => $suspension) {
                if ($now - $suspension[1] > 10) {
                    $expired[] = $id;
                }
            }
            foreach ($expired as $id) {
                if (isset($this->suspensions[$id])) {
                    try {
                        $this->suspensions[$id][0]->throw(new Exception('conduit request timeout'));
                    } catch (Throwable) {
                        // Already resolved
                    }
                    unset($this->suspensions[$id]);
                }
            }
        });
    }

    public function get(string $name)
    {
        return $this->sendAndRecv(Command::create('get', $name));
    }

    public function set(string $name, $value)
    {
        $this->send(Command::create('set', $name, $value));
    }

    public function inc(string $name, int $step = 1)
    {
        return $this->sendAndRecv(Command::create('inc', $name, $step));
    }

    public function sAdd(string $name, ...$value)
    {
        $this->send(Command::create('sAdd', $name, $value));
    }

    public function sRem(string $name, ...$value)
    {
        $this->send(Command::create('sRem', $name, $value));
    }

    public function sMembers(string $name)
    {
        return $this->sendAndRecv(Command::create('sMembers', $name));
    }

    public function publish(string $name, $value)
    {
        $this->send(Command::create('publish', $name, $value));
    }

    public function subscribe(string $name, $callback)
    {
        $this->events[$name] = $callback;
        if ($this->connection && $this->connection->getStatus()) {
            try {
                $this->send(Command::create('subscribe', $name));
            } catch (Throwable) {
                // Will retry after reconnect
            }
        }
    }

    protected function sendAndRecv(Command $command)
    {
        $suspension = EventLoop::getSuspension();
        $id         = $this->id++;
        $command->id = $id;
        $this->suspensions[$id] = [$suspension, time()];

        try {
            $this->send($command);
        } catch (Throwable $e) {
            unset($this->suspensions[$id]);
            throw $e;
        }

        return $suspension->suspend();
    }

    protected function send(Command $command)
    {
        if (!$this->connection || !$this->connection->getStatus()) {
            throw new Exception('conduit connection is disconnected');
        }

        $json = @json_encode([
            'type' => 'command',
            'id'   => $command->id,
            'name' => $command->name,
            'key'  => $command->key,
            'data' => $command->data,
        ]);

        if ($json === false) {
            throw new Exception('conduit message encoding failed: ' . json_last_error_msg());
        }

        $this->connection->send($json);
    }

    protected function createConnection(?Suspension $suspension = null): AsyncTcpConnection
    {
        $connection           = new AsyncTcpConnection($this->domain);
        $connection->protocol = Frame::class;

        $connection->onConnect = function () use ($suspension) {
            $this->reconnectAttempts = 0;
            $this->clearTimer();

            if ($suspension) {
                try {
                    $suspension->resume();
                } catch (Throwable) {
                    // Already resolved or cancelled
                }
            }

            foreach (array_keys($this->events) as $name) {
                try {
                    $this->send(Command::create('subscribe', $name));
                } catch (Throwable) {
                    // Individual subscription failure should not break the connection
                }
            }
        };

        $connection->onMessage = function ($connection, $buffer) {
            $decoded = json_decode((string) $buffer, true);
            if (!is_array($decoded) || !isset($decoded['type'])) {
                return;
            }

            if ($decoded['type'] === 'event' && isset($decoded['name']) && isset($decoded['data'])) {
                if (isset($this->events[$decoded['name']])) {
                    try {
                        $this->events[$decoded['name']]($decoded['data']);
                    } catch (Throwable) {
                        // Prevent event handler exceptions from breaking the connection
                    }
                }
            } elseif ($decoded['type'] === 'result' && isset($decoded['id']) && isset($this->suspensions[$decoded['id']])) {
                $susp = $this->suspensions[$decoded['id']][0];
                unset($this->suspensions[$decoded['id']]);
                try {
                    $susp->resume($decoded['data'] ?? null);
                } catch (Throwable) {
                    // Suspension might already be cancelled
                }
            }
        };

        $connection->onClose = function () {
            $this->connection = null;
            $this->clearTimer();

            foreach (array_keys($this->suspensions) as $id) {
                try {
                    $this->suspensions[$id][0]->throw(new Exception('conduit connection closed'));
                } catch (Throwable) {
                    // Already resolved
                }
                unset($this->suspensions[$id]);
            }

            if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
                $this->reconnectAttempts++;
                $delay = min($this->reconnectBaseDelay * (1 << ($this->reconnectAttempts - 1)), 30);
                $this->reconnectTimer = Timer::add($delay, function () {
                    $this->connection = $this->createConnection();
                }, [], false);
            }
        };

        $connection->onError = function () {
            // Connection errors are handled by onClose
        };

        $connection->connect();

        return $connection;
    }

    protected function clearTimer(): void
    {
        if ($this->reconnectTimer !== null) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
    }
}