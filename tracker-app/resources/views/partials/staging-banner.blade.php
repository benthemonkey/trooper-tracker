{{--
    Midwest only, temporary: tells members on the test site (APP_ENV=staging) that what they
    enter is wiped by the weekly refresh from the live legacy tracker. Remove this file and its
    @include in partials/navbar.blade.php when staging goes away.
--}}
@if(app()->environment('staging'))
    <div class="alert alert-warning rounded-0 border-0 mb-0 py-2 text-center small" role="status">
        <i class="fa fa-fw fa-flask me-1"></i>
        <strong>This is the test site.</strong>
        Anything you enter here is erased every week and replaced with the latest data from the
        real Troop Tracker. Keep signing up for troops on the
        <a href="https://midwestgarrison.com/tracker/" class="alert-link">real tracker</a>.
    </div>
@endif
