<?php

declare(strict_types=1);

namespace tests\feature;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use RuntimeException;

class HttpTest extends TestCase
{
    protected const PORT               = 8080;
    protected static ?Process $process = null;
    protected Client $httpClient;

    /**
     * 强制杀掉占用 8080 端口的残留进程，避免跨测试用例端口冲突
     */
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
            // macOS/Linux: lsof
            $output = [];
            @exec("lsof -nP -iTCP:{$port} -sTCP:LISTEN -t 2>/dev/null", $output);
            foreach ($output as $pid) {
                $pid = (int) trim($pid);
                if ($pid > 0) {
                    @posix_kill($pid, 9);
                }
            }
        }
        // 等待端口释放
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
        $hotFile = STUB_DIR . '/route/hot.php';
        if (file_exists($hotFile)) {
            @unlink($hotFile);
        }

        // 清理 runtime 下的 pid/status/socket，避免 conduit.sock 重用问题
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
                'PHP_WEBSOCKET_ENABLE' => 'false',
                'PHP_QUEUE_ENABLE'     => 'false',
            ]
        );
        self::$process->start();

        if (!self::waitForPort()) {
            $out = self::$process->getOutput() . "\n---ERR---\n" . self::$process->getErrorOutput();
            self::$process->stop();
            self::$process = null;

            throw new RuntimeException('Http server failed to start. output: ' . $out);
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

        $hotFile = STUB_DIR . '/route/hot.php';
        if (file_exists($hotFile)) {
            @unlink($hotFile);
        }
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

    public function testCallbackRoute(): void
    {
        $response = $this->httpClient->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello world', $response->getBody()->getContents());
    }

    public function testControllerRoute(): void
    {
        $jar = new CookieJar();

        $response = $this->httpClient->get('/test', ['cookies' => $jar]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test', $response->getBody()->getContents());

        // 直接检查响应头 Set-Cookie，确保服务端下发了 cookie
        $setCookieHeader = $response->getHeaderLine('Set-Cookie');
        $this->assertNotEmpty($setCookieHeader, 'Expected Set-Cookie header to be present. Raw headers: ' . json_encode($response->getHeaders()));
        $this->assertStringContainsString('name=', $setCookieHeader);
        $this->assertStringContainsString('think', $setCookieHeader);

        // 跳过 CookieJar 校验（Jar 可能因 Path/Domain 规则未入库，直接解析 Set-Cookie 头更稳定）
        $found = false;
        $value = null;
        foreach (explode(',', $setCookieHeader) as $headerLine) {
            $headerLine = trim($headerLine);
            if (str_starts_with($headerLine, 'name=')) {
                $parts = explode(';', $headerLine, 2);
                $value = urldecode(explode('=', $parts[0], 2)[1]);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Cookie "name" not found in Set-Cookie header: ' . $setCookieHeader);
        $this->assertSame('think', $value);
    }

    public function testJsonPost(): void
    {
        $data = [
            'name' => 'think',
        ];
        $response = $this->httpClient->post('/json', [
            'json' => $data,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(json_encode($data), $response->getBody()->getContents());
    }

    public function testPutAndDeleteRequest(): void
    {
        $response = $this->httpClient->put('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('put', $response->getBody()->getContents());

        $response = $this->httpClient->delete('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('delete', $response->getBody()->getContents());
    }

    public function testFileResponse(): void
    {
        $response = $this->httpClient->get('/static/asset.txt');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            file_get_contents(STUB_DIR . '/public/asset.txt'),
            $response->getBody()->getContents()
        );
    }

    public function testSse(): void
    {
        $response = $this->httpClient->get('/sse', [
            'stream'  => true,
            'timeout' => 5,
        ]);

        $body = $response->getBody();

        $buffer = '';
        while (!$body->eof()) {
            $text = $body->read(1);
            if ($text === '') {
                continue;
            }
            if ($text === "\r") {
                continue;
            }
            $buffer .= $text;
            if ($text === "\n") {
                if ($buffer !== "\n") {
                    $this->assertStringStartsWith('data: ', $buffer);
                }
                $buffer = '';
            }
        }
    }

    public function testHotUpdate(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Hot update test skipped on Windows.');
        }

        $hotFile = STUB_DIR . '/route/hot.php';

        try {
            $response = $this->httpClient->get('/hot');
            $this->assertSame(404, $response->getStatusCode(), 'Expect /hot to be 404 before hot file creation.');

            $route = <<<'PHP'
<?php

use think\facade\Route;

Route::get('/hot', function () {
    return 'hot';
});
PHP;

            file_put_contents($hotFile, $route);

            // worker 默认监控间隔 2s，保守等待 3s+
            sleep(4);

            $response = $this->httpClient->get('/hot');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('hot', $response->getBody()->getContents());
        } finally {
            if (file_exists($hotFile)) {
                @unlink($hotFile);
            }
        }
    }
}
