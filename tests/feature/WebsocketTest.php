<?php

namespace think\worker\tests\feature;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use Symfony\Component\Process\Process;
use function Ratchet\Client\connect;

class WebsocketTest extends TestCase
{
    private static ?Process $process = null;
    private ?Client $httpClient = null;

    public static function setUpBeforeClass(): void
    {
        self::$process = new Process(['php', 'think', 'worker'], STUB_DIR, [
            'PHP_WEBSOCKET_ENABLE' => 'true',
            'PHP_QUEUE_ENABLE'     => 'false',
            'PHP_HOT_ENABLE'       => 'false',
        ]);
        self::$process->start();
        $wait = 0;

        while (!self::$process->getOutput()) {
            $wait++;
            if ($wait > 30) {
                throw new \Exception('server start failed');
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        echo self::$process->getOutput();
        self::$process->stop();
    }

    protected function setUp(): void
    {
        $this->httpClient = new Client([
            'base_uri'    => 'http://127.0.0.1:8080',
            'cookies'     => true,
            'http_errors' => false,
            'timeout'     => 1,
        ]);
    }

    public function test_http(): void
    {
        $response = $this->httpClient->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello world', $response->getBody()->getContents());
    }

    public function test_websocket(): void
    {
        $connected = 0;
        $messages  = [];
        connect('ws://127.0.0.1:8080/websocket')
            ->then(function (\Ratchet\Client\WebSocket $conn) use (&$connected, &$messages) {
                $connected++;
                $conn->on('message', function ($msg) use ($conn, &$messages) {
                    $messages[] = (string) $msg;
                    $conn->close();
                });
            });

        connect('ws://127.0.0.1:8080/websocket')
            ->then(function (\Ratchet\Client\WebSocket $conn) use (&$connected, &$messages) {
                $connected++;
                $conn->on('message', function ($msg) use ($conn, &$messages) {
                    $messages[] = (string) $msg;
                    $conn->close();
                });

                $conn->send('hello');
            });

        Loop::get()->run();

        $this->assertSame(2, $connected);
        $this->assertSame(['hello', 'hello'], $messages);
    }
}