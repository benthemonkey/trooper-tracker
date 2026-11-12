<?php

declare(strict_types=1);

namespace TroopTracker\Plugins\Sentry\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TroopTracker\Plugins\Sentry\SentryServiceProvider;

/**
 * Sentry's browser SDK, served from the tracker's own site. Ad blockers block Sentry's CDN
 * (browser.sentry-cdn.com), so a page loading it from there reports nothing for those members;
 * Sentry's documented fix is to host the bundle yourself.
 * https://docs.sentry.io/platforms/javascript/troubleshooting/#self-hosting-cdn-bundles
 */
final class BrowserSdkController
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(SentryServiceProvider::browserSdkFile(), [
            'Content-Type' => 'text/javascript; charset=utf-8',
            //  the address carries the version, so browsers can keep it until the next upgrade
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
