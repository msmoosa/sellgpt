<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\GenerateController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])
    ->middleware(['verify.shopify'])->name('home');



// API endpoint: GET /api/generate (no CSRF required)
Route::get('/api/generate', [GenerateController::class, 'index'])
    ->middleware(['verify.shopify']);

// Endpoint to retrieve LLMs.txt file by shop domain
Route::get('/llms', [GenerateController::class, 'show']);
Route::get('/app/sellgpt/llms', [GenerateController::class, 'show']);
// API endpoint: GET /api/store - returns current store with llm_generated flag
Route::get('/api/store', [HomeController::class, 'current']);