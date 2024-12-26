<?php

use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Process\Process;
use GuzzleHttp\Client;

$process = null;
beforeAll(function () use (&$process) {
    $process = new Process(['php', 'think', 'worker'], __DIR__ . '/stub/');
    $process->start();
    $wait = 0;

    while (!$process->getOutput()) {
        $wait++;
        if ($wait > 10) {
            throw new Exception('server start failed');
        }
        sleep(1);
    }
});

afterAll(function () use (&$process) {
    echo $process->getOutput();
    $process->stop();
});

beforeEach(function () {
    $this->client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);
});

it('callback route', function () {
    $response = $this->client->get('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('hello world');
});

it('controller route', function () {
    $jar = new CookieJar();

    $response = $this->client->get('/test', ['cookies' => $jar]);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('test')
        ->and($jar->getCookieByName('name')->getValue())
        ->toBe('think');
});

it('json post', function () {

    $data     = [
        'name' => 'think',
    ];
    $response = $this->client->post('/json', [
        'json' => $data,
    ]);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe(json_encode($data));
});

it('put and delete request', function () {
    $response = $this->client->put('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('put');

    $response = $this->client->delete('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('delete');
});

it('file response', function () {
    $response = $this->client->get('/static/asset.txt');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe(file_get_contents(__DIR__ . '/stub/public/asset.txt'));
});

it('hot update', function () {
    $response = $this->client->get('/hot');

    expect($response->getStatusCode())
        ->toBe(404);

    $route = <<<PHP
<?php

use think\\facade\\Route;

Route::get('/hot', function () {
    return 'hot';
});
PHP;

    file_put_contents(__DIR__ . '/stub/route/hot.php', $route);

    sleep(2);

    $response = $this->client->get('/hot');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('hot');
})->after(function () {
    @unlink(__DIR__ . '/stub/route/hot.php');
})->skipOnWindows();
