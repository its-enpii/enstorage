<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\GoogleAccountController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RecentController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (prefix: /api/v1)
|--------------------------------------------------------------------------
*/

// Public auth
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'googleAuth']);
    Route::get('google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);
});

// API docs (public, no auth)
Route::get('docs', [DocsController::class, 'ui']);
Route::get('docs/openapi.yaml', [DocsController::class, 'spec']);

// Public share link (tanpa auth)
Route::get('s/{token}', [FileController::class, 'viewByToken']);
Route::get('s/{token}/view', [FileController::class, 'view']);

// Google OAuth bridge — public, no auth. Google's redirect_uri MUST
// be a valid HTTPS public domain (custom URI schemes are rejected
// by Google for both Web and Android OAuth client types). This
// endpoint serves as that HTTPS destination, then returns HTML that
// JS-redirects to enstorage://oauth-callback?code=...&state=... for
// the mobile app's in-app WebView to intercept.
Route::get('google-accounts/oauth/callback-web', [GoogleAccountController::class, 'callbackWeb']);

// Protected
Route::middleware('auth.apikey')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::patch('auth/me', [AuthController::class, 'updateMe']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    Route::patch('auth/locale', [AuthController::class, 'updateLocale']);

    // Notifications
    Route::post('notifications/token', [NotificationController::class, 'registerToken']);
    Route::delete('notifications/token', [NotificationController::class, 'removeToken']);
    Route::get('notifications/settings', [NotificationController::class, 'getSettings']);
    Route::patch('notifications/settings', [NotificationController::class, 'updateSettings']);

    // API Keys (CRUD — Sanctum only, no API key allowed)
    Route::middleware('auth.sanctum.only')->prefix('api-keys')->group(function () {
        Route::get('/', [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'store']);
        Route::delete('{id}', [ApiKeyController::class, 'destroy']);
    });

    // Webhooks (CRUD — Sanctum only, no API key allowed)
    Route::middleware('auth.sanctum.only')->prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index']);
        Route::post('/', [WebhookController::class, 'store']);
        Route::patch('{id}', [WebhookController::class, 'update']);
        Route::delete('{id}', [WebhookController::class, 'destroy']);
    });

    // Google Accounts
    Route::prefix('google-accounts')->group(function () {
        Route::get('oauth/redirect', [GoogleAccountController::class, 'redirect']);
        // /oauth/callback hanya tersedia via web route (/connect/google/callback)
        // agar tidak konflik dengan format Sanctum-protected API.
        // Mobile flow: app intercept custom URL scheme callback dan POST ke sini.
        Route::post('oauth/exchange', [GoogleAccountController::class, 'exchange']);
        // Mobile WebView flow: WebView intercepts enstorage://oauth-callback
        // navigation, extracts code+state, POSTs here for backend to exchange.
        Route::post('oauth/callback', [GoogleAccountController::class, 'mobileCallback']);
        Route::get('/', [GoogleAccountController::class, 'index']);
        Route::get('{id}', [GoogleAccountController::class, 'show']);
        Route::patch('{id}', [GoogleAccountController::class, 'update']);
        Route::delete('{id}', [GoogleAccountController::class, 'destroy']);
        Route::post('{id}/sync-quota', [GoogleAccountController::class, 'syncQuota']);
    });

    // Storage summary
    Route::get('storage/summary', [StorageController::class, 'summary']);

    // Files
    Route::post('files/upload', [FileUploadController::class, 'upload']);
    Route::get('files', [FileController::class, 'index']);
    Route::get('files/{id}', [FileController::class, 'show']);
    Route::get('files/{id}/status', [FileController::class, 'status']);
    Route::get('files/{id}/download', [FileController::class, 'download']);
    Route::get('files/{id}/thumbnail', [FileController::class, 'thumbnail']);
    Route::patch('files/{id}', [FileController::class, 'update']);
    Route::put('files/{id}/move', [FileController::class, 'move']);
    Route::delete('files/{id}', [FileController::class, 'destroy']);
    Route::post('files/bulk-delete', [FileController::class, 'bulkDestroy']);
    Route::post('files/{id}/share', [FileController::class, 'share']);
    Route::delete('files/{id}/share', [FileController::class, 'unshare']);

    // Folders
    Route::get('folders', [FolderController::class, 'index']);
    Route::post('folders', [FolderController::class, 'store']);
    Route::get('folders/{id}', [FolderController::class, 'show']);
    Route::patch('folders/{id}', [FolderController::class, 'update']);
    Route::put('folders/{id}/move', [FolderController::class, 'move']);
    Route::delete('folders/{id}', [FolderController::class, 'destroy']);
    Route::post('folders/{id}/share', [FolderController::class, 'share']);
    Route::delete('folders/{id}/share', [FolderController::class, 'unshare']);

    // Recent (root-level folders + files, mixed, cursor-paginated)
    Route::get('recent', [RecentController::class, 'index']);

    // Owner-only
    Route::middleware('role:owner')->prefix('admin')->group(function () {
        Route::get('ping', fn () => response()->json(['ok' => true]));
    });
});

// API Key scope + throttle + log — hanya berlaku jika request pakai API key
// (CheckScope/ThrottleApiKey/ActivityLogApiKey skip otomatis untuk Sanctum).
Route::middleware(['throttle.apikey', 'log.apikey'])->group(function () {
    Route::middleware('check.scope:write')->group(function () {
        Route::post('files/upload', [FileUploadController::class, 'upload']);
        Route::patch('files/{id}', [FileController::class, 'update']);
        Route::put('files/{id}/move', [FileController::class, 'move']);
        Route::post('folders', [FolderController::class, 'store']);
        Route::patch('folders/{id}', [FolderController::class, 'update']);
        Route::put('folders/{id}/move', [FolderController::class, 'move']);
        Route::post('google-accounts/{id}/sync-quota', [GoogleAccountController::class, 'syncQuota']);
    });

    Route::middleware('check.scope:delete')->group(function () {
        Route::delete('google-accounts/{id}', [GoogleAccountController::class, 'destroy']);
        Route::delete('files/{id}', [FileController::class, 'destroy']);
        Route::delete('folders/{id}', [FolderController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| API Key routes (Sanctum OR X-API-Key, dengan scope check + log)
|--------------------------------------------------------------------------
| Semua endpoint bisa diakses via Sanctum token (web/mobile user)
| ATAU API key (machine-to-machine). Scope hanya berlaku untuk API key.
| Endpoint /api-keys/* TIDAK bisa diakses via API key (hanya Sanctum).
*/
Route::middleware(['auth.apikey', 'throttle.apikey', 'log.apikey'])->group(function () {
    // Read scope
    Route::middleware('check.scope:read')->group(function () {
        Route::get('storage/summary', [StorageController::class, 'summary']);
        Route::get('google-accounts', [GoogleAccountController::class, 'index']);
        Route::get('google-accounts/{id}', [GoogleAccountController::class, 'show']);
        Route::get('files', [FileController::class, 'index']);
        Route::get('files/{id}', [FileController::class, 'show']);
        Route::get('files/{id}/status', [FileController::class, 'status']);
        Route::get('files/{id}/download', [FileController::class, 'download']);
        Route::get('files/{id}/thumbnail', [FileController::class, 'thumbnail']);
        Route::get('folders', [FolderController::class, 'index']);
        Route::get('folders/{id}', [FolderController::class, 'show']);
        Route::get('recent', [RecentController::class, 'index']);
        Route::get('search/files', [SearchController::class, 'searchFiles']);
    });

    // Write scope
    Route::middleware('check.scope:write')->group(function () {
        Route::post('google-accounts/{id}/sync-quota', [GoogleAccountController::class, 'syncQuota']);
        Route::post('files/upload', [FileUploadController::class, 'upload']);
        Route::patch('files/{id}', [FileController::class, 'update']);
        Route::put('files/{id}/move', [FileController::class, 'move']);
        Route::post('folders', [FolderController::class, 'store']);
        Route::patch('folders/{id}', [FolderController::class, 'update']);
        Route::put('folders/{id}/move', [FolderController::class, 'move']);
    });

    // Delete scope
    Route::middleware('check.scope:delete')->group(function () {
        Route::delete('google-accounts/{id}', [GoogleAccountController::class, 'destroy']);
        Route::delete('files/{id}', [FileController::class, 'destroy']);
        Route::delete('folders/{id}', [FolderController::class, 'destroy']);
    });
});

