<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('vault');
});

Route::get('/vault', function () {
    return view('vault');
});
