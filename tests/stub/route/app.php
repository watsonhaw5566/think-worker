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

Route::get('test', 'index/test');
Route::post('json', 'index/json');

Route::get('static/:path', function (string $path) {
    $filename = public_path() . $path;
    return new \think\worker\response\File($filename);
})->pattern(['path' => '.*\.\w+$']);
