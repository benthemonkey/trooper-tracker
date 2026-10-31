@auth
    <a class="navbar-brand"
       href="{{ route('events.list') }}">
        <img class="rounded p-0 m-0"
             height="24px"
             width="24px"
             src="{{ url(config('tracker.navbar_icon')) }}" />
        <span class="ms-1">Home</span>
    </a>
@else
    <a class="navbar-brand"
       href="{{ route('home') }}">
        <img class="rounded p-0 m-0"
             height="24px"
             width="24px"
             src="{{ url(config('tracker.navbar_icon')) }}" />
        <span class="ms-1">Troop Tracker</span>
    </a>
@endauth