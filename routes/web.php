<?php

use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnsureSsoEnabled;
use App\Http\Middleware\RequireUsableInvitation;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['guest', 'throttle:invitations', RequireUsableInvitation::class])->group(function () {
    Route::livewire('invitations/{token}', 'pages::auth.accept-invitation')->name('invitations.accept');
});

Route::middleware([EnsureSsoEnabled::class, 'throttle:sso'])->prefix('auth/sso')->group(function () {
    Route::get('redirect', [SsoController::class, 'redirect'])->middleware('guest')->name('sso.redirect');
    Route::get('callback', [SsoController::class, 'callback'])->name('sso.callback');
    Route::get('test', [SsoController::class, 'test'])->middleware(['auth', 'verified', 'second-factor', 'can:settings.sso'])->name('sso.test');
});

Route::middleware(['auth', 'verified', 'second-factor'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
