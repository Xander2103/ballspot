@extends('club-portal.layout')

@section('title', __('club_portal.competitions.create_title') . ' · ' . $club->name)

@section('content')
<div class="mb-2"><a href="{{ route('club-portal.competitions.index', $club) }}" class="text-muted small">&larr; {{ __('club_portal.competitions.back') }}</a></div>
<h1 class="h4 mb-3">{{ __('club_portal.competitions.create_title') }}</h1>
<form method="POST" action="{{ route('club-portal.competitions.store', $club) }}" class="portal-card p-3" style="max-width: 860px" data-section="competition-editor">
    @csrf
    @include('club-portal.competitions._form')
    <button type="submit" class="btn btn-primary">{{ __('club_portal.competitions.create') }}</button>
</form>
@endsection
