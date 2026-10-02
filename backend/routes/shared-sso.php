<?php

use App\Http\Controllers\SharedSsoController;
use Illuminate\Support\Facades\Route;

// Included from web.php: host-only server cookies, CSRF and locked callback state.
Route::prefix('api/auth/sso')->group(function () {
    Route::get('login', [SharedSsoController::class, 'loginPage']);
    Route::get('onboard', [SharedSsoController::class, 'onboardPage']);
    Route::post('onboard', [SharedSsoController::class, 'onboard'])->block(10, 10)->middleware('throttle:5,1');
    Route::get('account', [SharedSsoController::class, 'accountPage'])->middleware(['sso.session', 'auth']);
    Route::get('status', [SharedSsoController::class, 'status']);
    Route::get('start', [SharedSsoController::class, 'start'])->block(10, 10)->middleware('throttle:10,1');
    Route::post('link', [SharedSsoController::class, 'link'])->block(10, 10)->middleware('throttle:5,1');
    Route::get('callback', [SharedSsoController::class, 'callback'])->block(10, 10)->middleware('throttle:10,1');
    Route::post('logout', [SharedSsoController::class, 'logout'])->block(10, 10);
});
