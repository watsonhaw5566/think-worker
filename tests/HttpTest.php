<?php

use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Process\Process;
use GuzzleHttp\Client;

$process = null;
beforeAll(function () use (&$process) {
    $process = new Process(['php', 'think', 'worker'], __DIR__ . '/stub/');
    $process->start();
    usleep(250000);
});

afterAll(function () use (&$process) {
    echo $process->getOutput();
    $process->stop();
});

test('tests callback route', function () {
    $client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);

    $response = $client->get('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('hello world');
});

test('tests controller route', function () {
    $jar    = new CookieJar();
    $client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => $jar,
        'http_errors' => false,
    ]);

    $response = $client->get('/test');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('test')
        ->and($jar->getCookieByName('name')->getValue())
        ->toBe('think');
});

test('tests json post', function () {
    $client   = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);
    $data     = [
        'name' => 'think',
    ];
    $response = $client->post('/json', [
        'json' => $data,
    ]);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe(json_encode($data));
});

test('tests put and delete request', function () {
    $client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);

    $response = $client->put('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('put');

    $response = $client->delete('/');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('delete');
});

test('tests file response', function () {
    $client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);

    $response = $client->get('/static/asset.txt');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe(file_get_contents(__DIR__ . '/stub/public/asset.txt'));
});

test('tests hot update', function () {

    $client = new Client([
        'base_uri'    => 'http://127.0.0.1:8080',
        'cookies'     => true,
        'http_errors' => false,
    ]);

    $response = $client->get('/hot');

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

    $response = $client->get('/hot');

    expect($response->getStatusCode())
        ->toBe(200)
        ->and($response->getBody()->getContents())
        ->toBe('hot');

    unlink(__DIR__ . '/stub/route/hot.php');
});
