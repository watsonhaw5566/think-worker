<?php

namespace think\worker\tests\feature;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class HttpTest extends TestCase
{
    private static ?Process $process = null;
    private ?Client $httpClient = null;

    public static function setUpBeforeClass(): void
    {
        self::$process = new Process(['php', 'think', 'worker'], STUB_DIR, [
            'PHP_WEBSOCKET_ENABLE' => 'false',
            'PHP_QUEUE_ENABLE'     => 'false',
        ]);
        self::$process->start();
        $wait = 0;

        while (!self::$process->getOutput()) {
            $wait++;
            if ($wait > 30) {
                throw new \Exception('server start failed');
            }
            sleep(1);
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

    public function test_callback_route(): void
    {
        $response = $this->httpClient->get('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello world', $response->getBody()->getContents());
    }

    public function test_controller_route(): void
    {
        $jar = new CookieJar();

        $response = $this->httpClient->get('/test', ['cookies' => $jar]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test', $response->getBody()->getContents());
        $this->assertSame('think', $jar->getCookieByName('name')->getValue());
    }

    public function test_json_post(): void
    {
        $data     = [
            'name' => 'think',
        ];
        $response = $this->httpClient->post('/json', [
            'json' => $data,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(json_encode($data), $response->getBody()->getContents());
    }

    public function test_put_and_delete_request(): void
    {
        $response = $this->httpClient->put('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('put', $response->getBody()->getContents());

        $response = $this->httpClient->delete('/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('delete', $response->getBody()->getContents());
    }

    public function test_file_response(): void
    {
        $response = $this->httpClient->get('/static/asset.txt');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(file_get_contents(STUB_DIR . '/public/asset.txt'), $response->getBody()->getContents());
    }

    public function test_sse(): void
    {
        $response = $this->httpClient->get('/sse', [
            'stream'  => true,
            'timeout' => 3,
        ]);

        $body = $response->getBody();

        $buffer = '';
        while (!$body->eof()) {
            $text = $body->read(1);
            if ($text == "\r") {
                continue;
            }
            $buffer .= $text;
            if ($text == "\n") {
                if ($buffer != "\n") {
                    $this->assertStringStartsWith('data: ', $buffer);
                }
                $buffer = '';
            }
        }
    }

    public function test_hot_update(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Skip on Windows');
        }

        $response = $this->httpClient->get('/hot');

        $this->assertSame(404, $response->getStatusCode());

        $route = <<<'PHP'
<?php

use think\facade\Route;

Route::get('/hot', function () {
    return 'hot';
});
PHP;

        file_put_contents(STUB_DIR . '/route/hot.php', $route);

        sleep(2);

        try {
            $response = $this->httpClient->get('/hot');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('hot', $response->getBody()->getContents());
        } finally {
            @unlink(STUB_DIR . '/route/hot.php');
        }
    }
}