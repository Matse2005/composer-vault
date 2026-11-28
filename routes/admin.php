<?php

use App\Http\Controllers\IncidentTimelineController;
use App\Http\Controllers\PersonalDataController;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Every page of the vault administration. All of them need a logged in admin with a verified address and a second
| factor. The `can:` middleware gives a role without the permission a 403 before the page loads; the actions check
| the same permissions again.
|
*/

Route::middleware(['auth', 'verified', 'second-factor'])->group(function () {
    if (app()->environment('local')) {
        Route::view('_styleguide', 'styleguide')->name('styleguide');
    }

    Route::livewire('projects', 'pages::projects.index')->name('projects.index')->middleware('can:'.Ability::ProjectsView);

    Route::livewire('projects/create', 'pages::projects.create')->name('projects.create')->middleware('can:'.Ability::ProjectsManage);
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show')->middleware('can:'.Ability::ProjectsView);

    Route::scopeBindings()->prefix('projects/{project}')->group(function () {
        Route::livewire('credentials/create', 'pages::credentials.create')->name('credentials.create')->middleware('can:'.Ability::CredentialsManage);
        Route::livewire('credentials/{credential}', 'pages::credentials.edit')->name('credentials.edit')->middleware('can:'.Ability::CredentialsManage);

        Route::livewire('tokens/create', 'pages::tokens.create')->name('tokens.create')->middleware('can:'.Ability::TokensManage);
        Route::livewire('tokens/{accessToken}', 'pages::tokens.show')->name('tokens.show')->middleware('can:'.Ability::TokensManage);
    });

    Route::middleware('can:'.Ability::LogsView)->group(function () {
        Route::livewire('logs/access', 'pages::logs.access')->name('logs.access');
        Route::livewire('logs/audit', 'pages::logs.audit')->name('logs.audit');
        Route::livewire('security-status', 'pages::security-status')->name('security-status');
    });

    Route::get('personal-data/{user}', PersonalDataController::class)->name('personal-data.export');

    Route::middleware('can:'.Ability::ComplianceView)->group(function () {
        Route::livewire('compliance/access-review', 'pages::compliance.access-review')->name('compliance.access-review');
        Route::livewire('compliance/report', 'pages::compliance.report')->name('compliance.report');
        Route::livewire('compliance/data-processing', 'pages::compliance.data-processing')->name('compliance.data-processing');
    });

    Route::middleware('can:'.Ability::IncidentsManage)->group(function () {
        Route::livewire('incidents', 'pages::incidents')->name('incidents');
        Route::get('incidents/{incident}/timeline.{format}', IncidentTimelineController::class)->name('incidents.timeline');
    });

    Route::livewire('admins', 'pages::admins.index')->name('admins.index')->middleware('can:'.Ability::AdminsView);
    Route::livewire('admin-roles', 'pages::admins.roles')->name('roles.index')->middleware('can:'.Ability::RolesManage);

    Route::livewire('admin-settings/approvals', 'pages::settings.approvals')->name('settings.approvals')->middleware('can:'.Ability::ApprovalManage);
    Route::livewire('admin-settings/api', 'pages::settings.api')->name('settings.api')->middleware('can:'.Ability::SettingsApi);
    Route::livewire('admin-settings/history', 'pages::settings.history')->name('settings.history')->middleware('can:settings.any');
    Route::livewire('admin-settings/organisation', 'pages::settings.organisation')->name('settings.organisation')->middleware('can:'.Ability::SettingsOrganisation);
    Route::livewire('admin-settings/sso', 'pages::settings.sso')->name('settings.sso')->middleware('can:'.Ability::SettingsSso);
    Route::livewire('admin-settings/access', 'pages::settings.access')->name('settings.access')->middleware('can:'.Ability::SettingsSecurity);
    Route::livewire('admin-settings/policies', 'pages::settings.policies')->name('settings.policies')->middleware('can:'.Ability::SettingsPolicies);
    Route::livewire('admin-settings/retention', 'pages::settings.retention')->name('settings.retention')->middleware('can:'.Ability::SettingsRetention);
    Route::livewire('admin-settings/notifications', 'pages::settings.notifications')->name('settings.notifications')->middleware('can:'.Ability::SettingsNotifications);
});
