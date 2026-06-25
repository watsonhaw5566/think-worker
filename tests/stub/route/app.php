<?php

use think\facade\Queue;
use think\facade\Route;

Route::get('/', function () {
    return 'hello world';
});

Route::put('/', function () {
    return 'put';
});

Route::delete('/', function () {
    return 'delete';
});

Route::get('/queue', function () {
    $path = sys_get_temp_dir() . '/think_worker_queue_test.log';
    @unlink($path);

    $token = hash('sha256', random_bytes(16));

    Queue::push('TestJob', ['path' => $path, 'token' => $token]);

    return json(['token' => $token, 'path' => $path]);
});

Route::get('/sse', function () {

    $generator = function () {
        foreach (range(0, 9) as $event) {
            yield 'data: ' . json_encode($event) . "\n\n";
        }

        yield "data: [DONE]\n\n";
    };

    $response = new \think\worker\response\Iterator($generator());

    return $response->header([
        'Content-Type'  => 'text/event-stream',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
});

Route::get('/websocket', function () {
    return (new \think\worker\response\Websocket())
        ->onOpen(function (\think\worker\Websocket $websocket) {
            $websocket->join('foo');
        })
        ->onMessage(function (\think\worker\Websocket $websocket, \think\worker\websocket\Frame $frame) {
            $websocket->to('foo')->push($frame->data);
        });
});

Route::get('test', 'index/test');
Route::post('json', 'index/json');

Route::get('static/:path', function (string $path) {
    $filename = public_path() . $path;
    return new \think\worker\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);