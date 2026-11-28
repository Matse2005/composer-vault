<?php

use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Every page of the vault administration. All of them need a logged in admin with a verified address and a second
| factor. The `can:` middleware gives a role without the capability a 403 before the page loads; the actions check
| the same gates again.
|
*/

Route::middleware(['auth', 'verified', 'second-factor'])->group(function () {
    Route::livewire('projects', 'pages::projects.index')->name('projects.index')->middleware('can:'.Ability::ViewVault);
    Route::livewire('credentials', 'pages::credentials.index')->name('credentials.index')->middleware('can:'.Ability::ViewVault);
    Route::livewire('tokens', 'pages::tokens.index')->name('tokens.index')->middleware('can:'.Ability::ViewVault);

    Route::middleware('can:'.Ability::ManageVault)->group(function () {
        Route::livewire('projects/create', 'pages::projects.create')->name('projects.create');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');

        Route::livewire('credentials/create', 'pages::credentials.create')->name('credentials.create');
        Route::livewire('credentials/{credential}', 'pages::credentials.edit')->name('credentials.edit');

        Route::livewire('tokens/create', 'pages::tokens.create')->name('tokens.create');
        Route::livewire('tokens/{accessToken}', 'pages::tokens.show')->name('tokens.show');
    });

    Route::middleware('can:'.Ability::ViewLogs)->group(function () {
        Route::livewire('logs/access', 'pages::logs.access')->name('logs.access');
        Route::livewire('logs/audit', 'pages::logs.audit')->name('logs.audit');
        Route::livewire('security-status', 'pages::security-status')->name('security-status');
    });

    Route::middleware('can:'.Ability::ManageAdmins)->group(function () {
        Route::livewire('admins', 'pages::admins.index')->name('admins.index');
    });

    Route::middleware('can:'.Ability::ManageSettings)->group(function () {
        Route::livewire('admin-settings/organisation', 'pages::settings.organisation')->name('settings.organisation');
        Route::livewire('admin-settings/sso', 'pages::settings.sso')->name('settings.sso');
        Route::livewire('admin-settings/access', 'pages::settings.access')->name('settings.access');
    });
});
