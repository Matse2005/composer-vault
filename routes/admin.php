<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Every page of the vault administration. All of them need a logged in admin with a verified address and a second
| factor, so there is one group.
|
*/

Route::middleware(['auth', 'verified', 'second-factor'])->group(function () {
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/create', 'pages::projects.create')->name('projects.create');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');

    Route::livewire('credentials', 'pages::credentials.index')->name('credentials.index');
    Route::livewire('credentials/create', 'pages::credentials.create')->name('credentials.create');
    Route::livewire('credentials/{credential}', 'pages::credentials.edit')->name('credentials.edit');

    Route::livewire('tokens', 'pages::tokens.index')->name('tokens.index');
    Route::livewire('tokens/create', 'pages::tokens.create')->name('tokens.create');
    Route::livewire('tokens/{accessToken}', 'pages::tokens.show')->name('tokens.show');

    Route::livewire('logs/access', 'pages::logs.access')->name('logs.access');
    Route::livewire('logs/audit', 'pages::logs.audit')->name('logs.audit');

    Route::livewire('admins', 'pages::admins.index')->name('admins.index');
});
