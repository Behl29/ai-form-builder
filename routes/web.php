<?php

use Illuminate\Support\Facades\Route;

// SPA routes - serve the React app
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
