@extends('layouts.email')

@section('message')
    <p>
        @if($to_coordinator ?? false)
            Esteemed Event Coordinator,
        @else
            Esteemed Command Staff,
        @endif
    </p>

    <p>
        <strong>{{ $poster->display_name }}</strong> has posted a comment on the following deployment and is requesting command staff attention:
    </p>

    @if($to_coordinator ?? false)
        <p>
            You're getting this as the event's coordinator.
        </p>
    @endif

    <p>
        <b>{{ $event->name }}</b>
    </p>

    <p>
        <a href="{{ route('events.display', compact('event')) }}">
            {{ route('events.display', compact('event')) }}
        </a>
    </p>

    @include('emails.inc.signature')

@endsection
