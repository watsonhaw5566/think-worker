<?php

namespace think\worker\tests\feature;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class QueueTest extends TestCase
{
    private static ?Process $process = null;

    private ?Client $httpClient = null;

    private static string $markerFile;

    public static function setUpBeforeClass(): void
    {
        self::$markerFile = sys_get_temp_dir() . '/think_worker_queue_test.log';
        @unlink(self::$markerFile);

        self::$process = new Process(['php', 'think', 'worker'], STUB_DIR, [
            // sync driver executes jobs inside push(), which is all we need to
            // cover the queue glue code used by think-worker without depending
            // on a running redis or database.
            'QUEUE_CONNECTION' => 'sync',
            'PHP_QUEUE_ENABLE' => 'true',
        ]);
        self::$process->start();

        $wait = 0;
        while (!self::$process->getOutput()) {
            $wait++;
            if ($wait > 30) {
                throw new \RuntimeException('worker server failed to start: ' . self::$process->getErrorOutput());
            }
            usleep(200_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            echo self::$process->getOutput();
            self::$process->stop();
        }
    }

    protected function setUp(): void
    {
        $this->httpClient = new Client([
            'base_uri'    => 'http://127.0.0.1:8080',
            'http_errors' => false,
            'timeout'     => 2,
        ]);
        @unlink(self::$markerFile);
    }

    /**
     * End-to-end: a request pushes a job through the queue inside think-worker.
     * With the sync connector the job fires synchronously inside Queue::push(),
     * which is enough to cover the think\Queue integration path exercised by
     * think-worker and does not require redis.
     */
    public function test_queue_job_runs_via_worker(): void
    {
        $response = $this->httpClient->get('/queue');

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('token', $body);
        $this->assertArrayHasKey('path', $body);

        // Sync connector runs the job inline inside Queue::push(), so the
        // marker file should already exist as soon as the HTTP response is
        // returned. Give it a brief moment just in case of buffering.
        $deadline = microtime(true) + 2;
        while (!is_file($body['path']) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($body['path'], 'Job marker file was not written; the queue job did not execute.');

        $lines   = array_filter(array_map('trim', file($body['path'])));
        $payload = json_decode(end($lines), true);

        $this->assertSame($body['token'], $payload['data']['token'] ?? null, 'Job payload did not match the token from the push request.');
    }

    /**
     * Direct unit test: push a job through think\Queue within a bare think
     * App instance (no workerman). Verifies our job class and the sync
     * connector work independently of the HTTP server.
     */
    public function test_sync_queue_push_instantiates_job(): void
    {
        $app = new \think\App(STUB_DIR);
        $app->initialize();

        // Ensure queue service is registered (think-queue does this via
        // Service.php which is picked up by topthink/framework).
        $queue = $app->make('queue');

        $token  = 'unit-' . bin2hex(random_bytes(8));
        $marker = sys_get_temp_dir() . '/think_worker_queue_test_unit.log';
        @unlink($marker);

        $queue->push('TestJob', ['path' => $marker, 'token' => $token]);

        $this->assertFileExists($marker);

        $lines   = array_filter(array_map('trim', file($marker)));
        $payload = json_decode(end($lines), true);

        $this->assertSame($token, $payload['data']['token'] ?? null);

        @unlink($marker);
    }
}