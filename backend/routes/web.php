<?php

use App\Http\Controllers\Api\GoogleAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// OAuth callback (web, no Bearer required — user identity carried via signed `state`).
// Google redirects browser here with ?code=...&state=...; controller exchanges code,
// attaches the Google account to the user encoded in state, then redirects to FE.
Route::get('/connect/google/callback', [GoogleAccountController::class, 'callback']);
