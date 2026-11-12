<?php

declare(strict_types=1);

namespace TroopTracker\Plugins\Sentry;

use App\Plugins\PluginServiceProvider;
use Illuminate\Log\Events\MessageLogged;
use Sentry\Event;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\RequestIntegration;
use TroopTracker\Plugins\Sentry\Http\Controllers\SentryTunnelController;
use TroopTracker\Plugins\Sentry\Listeners\ReportLoggedException;

use function Sentry\init;

class SentryServiceProvider extends PluginServiceProvider
{
    /**
     * Sentry's browser bundle, served by this plugin from resources/js (see BrowserSdkController),
     * pinned with its integrity hash. To upgrade, download
     * https://browser.sentry-cdn.com/<version>/bundle.min.js to resources/js/browser-sdk-<version>.min.js,
     * delete the old file and update both constants; a test checks the file against the hash.
     */
    public const BROWSER_SDK_VERSION = '11.10.0';

    public const BROWSER_SDK_INTEGRITY = 'sha384-O/8YQaGGVy/n9jIUzcOiaiJVm4t5+JMNiK/zOa0fWvlSld+FquXemEcwJxQ3x/Lr';

    public const TUNNEL_PATH = '/webhooks/sentry';

    public static function browserSdkFile(): string
    {
        return dirname(__DIR__).'/resources/js/browser-sdk-'.self::BROWSER_SDK_VERSION.'.min.js';
    }

    /**
     * Ad-block lists block Sentry's CDN and paths such as /sentry/bundle.min.js or
     * /sentry-browser.min.js, so the file gets a neutral name on the tracker's own site.
     */
    public static function browserSdkPath(): string
    {
        return '/plugins/sentry/browser-sdk-'.self::BROWSER_SDK_VERSION.'.js';
    }

    protected function registerPlugin(): void
    {
        $this->app->singleton(SentrySettings::class);
    }

    public function bootPlugin(): void
    {
        $settings = $this->app->make(SentrySettings::class);
        $dsn = $settings->serverDsn();

        if ($dsn !== null)
        {
            init([
                'dsn' => $dsn,
                'environment' => $settings->environment(),
                //  no IP addresses, cookies or request bodies: member data stays here
                'send_default_pii' => false,
                'max_request_body_size' => 'never',
                'before_send' => fn (Event $event): Event => self::withoutFrameVariables($event),
                //  Laravel already handles PHP errors and reports them through the log;
                //  Sentry's own global handlers would send warnings and duplicates
                'default_integrations' => false,
                'integrations' => [
                    new RequestIntegration,
                    new EnvironmentIntegration,
                    new FrameContextifierIntegration,
                ],
            ]);

            $this->listen(MessageLogged::class, ReportLoggedException::class);
        }

        $this->addToSlot(
            'page.head',
            'sentry::browser',
            fn (): array => [
                'sentry_options' => array_filter([
                    'dsn' => $settings->browserDsn(),
                    'environment' => $settings->environment(),
                    //  a path, not route(): the router's name index is built before plugins add routes
                    'tunnel' => $settings->tunnel() ? url(self::TUNNEL_PATH) : null,
                    'sendDefaultPii' => false,
                ], fn (mixed $value): bool => $value !== null),
                'sentry_user_id' => auth()->id(),
            ],
            fn (): bool => $settings->browserDsn() !== null,
        );

        $this->addWebhook('sentry', SentryTunnelController::class, 'sentry'); // = TUNNEL_PATH

        $this->addSystemCheck('Sentry server DSN', fn (): bool => $settings->serverDsn() !== null);
    }

    /**
     * Drop the function arguments PHP keeps on each stack frame (unless zend.exception_ignore_args
     * is on): they can hold member names, emails and form input.
     */
    public static function withoutFrameVariables(Event $event): Event
    {
        foreach ($event->getExceptions() as $exception)
        {
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame)
            {
                $frame->setVars([]);
            }
        }

        return $event;
    }
}
