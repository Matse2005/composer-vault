<?php

use App\Http\Controllers\Api\V1\ComposerAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('vault-api')->group(function (): void {
    Route::get('projects/{slug}/composer-auth', ComposerAuthController::class)
        ->where('slug', '[a-z][a-z0-9-]{1,63}')
        ->name('api.v1.composer-auth');
});
