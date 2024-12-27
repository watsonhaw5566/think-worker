<?php

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

Route::get('test', 'index/test');
Route::post('json', 'index/json');

Route::get('static/:path', function (string $path) {
    $filename = public_path() . $path;
    return new \think\worker\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);
