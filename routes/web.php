<?php

use App\Http\Middleware\RequireUsableInvitation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['guest', 'throttle:invitations', RequireUsableInvitation::class])->group(function () {
    Route::livewire('invitations/{token}', 'pages::auth.accept-invitation')->name('invitations.accept');
});

Route::middleware(['auth', 'verified', 'second-factor'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
