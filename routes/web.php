<?php

declare(strict_types=1);

use App\Http\Controllers\VaultViewController;
use Illuminate\Support\Facades\Route;

Route::get('/', VaultViewController::class);
Route::get('/vault', VaultViewController::class);
