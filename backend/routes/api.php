<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;

Route::post('/login', [AuthController::class, 'login']);

Route::get('/search',[ProductController::class,'search']);

Route::middleware('auth:api')->group(function () {
    Route::get('/orders', [OrderController::class,'index']);
    Route::get('/orders/{id}', [OrderController::class,'show']);
    Route::post('/orders', [OrderController::class,'store']);
    Route::put('/orders/{id}', [OrderController::class,'update']);
    Route::patch('/orders/{id}', [OrderController::class,'update']);
    Route::delete('/orders/{id}', [OrderController::class,'destroy']);
});