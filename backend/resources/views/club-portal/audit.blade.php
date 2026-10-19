@extends('club-portal.layout')

@section('title', __('club_portal.audit.title') . ' · ' . $club->name)

@section('content')
<h1 class="h4 mb-1">{{ __('club_portal.audit.title') }}</h1>
<p class="text-muted small">{{ __('club_portal.audit.intro') }}</p>

<form method="GET" action="{{ route('club-portal.audit.index', $club) }}" class="d-flex flex-wrap gap-2 align-items-end mb-3" data-section="audit-filters">
    <div>
        <label for="a-action" class="form-label small mb-1">{{ __('club_portal.audit.col_action') }}</label>
        <select id="a-action" name="action" class="form-select form-select-sm">
            <option value="">{{ __('club_portal.audit.any_action') }}</option>
            @foreach($actions as $action)
                <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ \Illuminate\Support\Facades\Lang::has('clubs.audit.' . $action) ? __('clubs.audit.' . $action) : $action }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="a-from" class="form-label small mb-1">{{ __('club_portal.common.from') }}</label>
        <input id="a-from" type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
    </div>
    <div>
        <label for="a-to" class="form-label small mb-1">{{ __('club_portal.common.to') }}</label>
        <input id="a-to" type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">{{ __('club_portal.common.filter') }}</button>
    @if($filtered)<a href="{{ route('club-portal.audit.index', $club) }}" class="btn btn-link btn-sm">{{ __('club_portal.common.reset') }}</a>@endif
</form>

<div class="portal-card p-2" data-section="audit-list">
    @include('club-portal._events', ['events' => $events, 'usernames' => $usernames, 'filtered' => $filtered])
</div>
@if($events->hasPages())<div class="mt-3">{{ $events->links('pagination::bootstrap-5') }}</div>@endif
@endsection
