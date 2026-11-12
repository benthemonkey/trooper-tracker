@php
    use TroopTracker\Plugins\Sentry\SentryServiceProvider;
@endphp
<script src="{{ url(SentryServiceProvider::browserSdkPath()) }}"
        integrity="{{ SentryServiceProvider::BROWSER_SDK_INTEGRITY }}"
        crossorigin="anonymous"></script>
<script>
    if (window.Sentry) {
        Sentry.init(@js($sentry_options));
        @if($sentry_user_id)
            Sentry.setUser({ id: @js((string) $sentry_user_id) });
        @endif
    }
</script>
