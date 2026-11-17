@inject ('crumbs', 'App\Services\BreadCrumbService')

{{-- Event coordinators use the event pages under admin/ but not the Command Staff dashboard;
     a member picked as an event's coordinator gets that event's pages and My Events, not the dashboard --}}
@php($manages_events = Auth::user()?->can('create', App\Models\Event::class) ?? false)
@php($trail = collect($crumbs->getCrumbs())->reject(
    fn ($crumb) => ($crumb->url === route('admin.display') && (Auth::user()?->is_event_coordinator || !$manages_events))
        || ($crumb->url === route('admin.events.list') && !(Auth::user()?->can('viewAny', App\Models\Event::class) ?? false))
))

@if ($trail->isNotEmpty())
    <div class="container-fluid">
        <nav class="mt-1">
            <ol class="breadcrumb border border-0">
                @foreach($trail as $crumb)
                    <li class="breadcrumb-item">
                        @if(empty($crumb->url))
                            {{ $crumb->title }}
                        @else
                            <a href="{{ $crumb->url }}">{{ $crumb->title }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>
@endif
