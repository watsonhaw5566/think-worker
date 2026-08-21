<?php

declare(strict_types=1);

namespace tests\feature;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use Symfony\Component\Process\Process;
use RuntimeException;
use Throwable;

use function Ratchet\Client\connect;

class WebsocketTest extends TestCase
{
    protected const PORT               = 8080;
    protected static ?Process $process = null;
    protected Client $httpClient;

    protected static function killPortProcesses(): void
    {
        $port = self::PORT;
        if (\PHP_OS_FAMILY === 'Windows') {
            $lines = [];
            @exec('netstat -ano 2>NUL', $lines);
            foreach ($lines as $line) {
                if (preg_match('/:' . $port . '\s.*LISTENING\s+(\d+)/i', $line, $m)) {
                    @exec('taskkill /F /PID ' . (int) $m[1] . ' 2>NUL');
                }
            }
        } else {
            $output = [];
            @exec("lsof -nP -iTCP:{$port} -sTCP:LISTEN -t 2>/dev/null", $output);
            foreach ($output as $pid) {
                $pid = (int) trim($pid);
                if ($pid > 0) {
                    @posix_kill($pid, 9);
                }
            }
        }
        usleep(300_000);
    }

    protected static function waitForPort(int $timeoutMs = 15000): bool
    {
        $end = microtime(true) + ($timeoutMs / 1000);
        do {
            $fp = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);

                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $end);

        return false;
    }

    public static function setUpBeforeClass(): void
    {
        // 清理 runtime，避免与前面 HttpTest 留下的 conduit.sock / pidfile 冲突
        foreach (glob(STUB_DIR . '/runtime/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        self::killPortProcesses();

        self::$process = new Process(
            ['php', 'think', 'worker'],
            STUB_DIR,
            [
                'PHP_WEBSOCKET_ENABLE' => 'true',
                'PHP_QUEUE_ENABLE'     => 'false',
                'PHP_HOT_ENABLE'       => 'false',
            ]
        );
        self::$process->start();

        if (!self::waitForPort()) {
            $out = self::$process->getOutput() . "\n---ERR---\n" . self::$process->getErrorOutput();
            self::$process->stop();
            self::$process = null;

            throw new RuntimeException('Websocket server failed to start. output: ' . $out);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process) {
            echo self::$process->getOutput();
            if (self::$process->getErrorOutput()) {
                echo "\n---ERR---\n" . self::$process->getErrorOutput();
            }
            self::$process->stop(3, SIGKILL);
            self::$process = null;
        }

        self::killPortProcesses();
    }

    protected function setUp(): void
    {
        $this->httpClient = new Client([
            'base_uri'    => 'http://127.0.0.1:' . self::PORT,
            'cookies'     => true,
            'http_errors' => false,
            'timeout'     => 2,
        ]);
    }

    public function testHttp(): void
    {
        $response = $this->httpClient->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello world', $response->getBody()->getContents());
    }

    public function testWebsocket(): void
    {
        $connected        = 0;
        $messages         = [];
        $errorMessage     = null;
        $expectedMessages = 2;

        $checkDone = function () use (&$messages, $expectedMessages) {
            if (count($messages) >= $expectedMessages) {
                // 所有客户端均已收到消息，安全停止事件循环
                Loop::get()->futureTick(function () {
                    Loop::get()->stop();
                });
            }
        };

        connect('ws://127.0.0.1:' . self::PORT . '/websocket')
            ->then(
                function (\Ratchet\Client\WebSocket $conn) use (&$connected, &$messages, &$errorMessage, $checkDone) {
                    $connected++;
                    $conn->on('message', function ($msg) use ($conn, &$messages, $checkDone) {
                        $messages[] = (string) $msg;
                        $conn->close();
                    });
                    $conn->on('error', function ($e) use (&$errorMessage) {
                        $errorMessage = $e->getMessage();
                        Loop::get()->stop();
                    });
                    $conn->on('close', $checkDone);
                },
                function ($e) use (&$errorMessage) {
                    $errorMessage = 'connect reject: ' . $e->getMessage();
                    Loop::get()->stop();
                }
            );

        connect('ws://127.0.0.1:' . self::PORT . '/websocket')
            ->then(
                function (\Ratchet\Client\WebSocket $conn) use (&$connected, &$messages, &$errorMessage, $checkDone) {
                    $connected++;
                    $conn->on('message', function ($msg) use ($conn, &$messages, $checkDone) {
                        $messages[] = (string) $msg;
                        $conn->close();
                    });
                    $conn->on('error', function ($e) use (&$errorMessage) {
                        $errorMessage = $e->getMessage();
                        Loop::get()->stop();
                    });
                    $conn->on('close', $checkDone);

                    $conn->send('hello');
                },
                function ($e) use (&$errorMessage) {
                    $errorMessage = 'connect(2) reject: ' . $e->getMessage();
                    Loop::get()->stop();
                }
            );

        // 超时保护
        $timeout = false;
        $timer   = Loop::get()->addTimer(6, function () use (&$timeout) {
            $timeout = true;
            Loop::get()->stop();
        });

        Loop::get()->run();

        try {
            Loop::get()->cancelTimer($timer);
        } catch (Throwable) {
        }

        if ($errorMessage !== null) {
            $this->fail('Websocket error: ' . $errorMessage . ' | connected=' . $connected . ' messages=' . json_encode($messages));
        }
        if ($timeout) {
            $this->fail('Websocket test timed out. connected=' . $connected . ' messages=' . json_encode($messages));
        }

        $this->assertSame(2, $connected, 'Expected 2 websocket clients to connect.');
        $this->assertSame(['hello', 'hello'], $messages, 'Both clients should receive the "hello" broadcast.');
    }
}
