<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TroopTracker\Plugins\Sentry\Http\Controllers\BrowserSdkController;
use TroopTracker\Plugins\Sentry\SentryServiceProvider;

// Served under /plugins/sentry, named plugins.sentry.*, for anyone (every page loads it).

Route::get('/browser-sdk-'.SentryServiceProvider::BROWSER_SDK_VERSION.'.js', BrowserSdkController::class)
    ->name('browser-sdk');
