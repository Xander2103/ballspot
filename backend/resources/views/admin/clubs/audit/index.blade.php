@extends('admin.layout')

@section('title', 'Club audit log')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Club audit log</h1>
        <div class="text-muted small">Every change to clubs, members, codes, packs, competitions and photos, across all clubs. Append-only; no emails or full codes are stored.</div>
    </div>
</div>

@if($filterClub)
    <div class="alert alert-light border py-2 small">Showing events of <a href="{{ route('admin.clubs.show', $filterClub) }}">{{ $filterClub->name }}</a> only.</div>
@endif

@include('admin.clubs._audit_filters', ['action' => route('admin.club-audit.index'), 'withClub' => true])

<div class="card shadow-sm">
    @include('admin.clubs._audit_table', ['events' => $events, 'showClub' => true])
</div>
@if($events->hasPages())<div class="mt-3">{{ $events->links('pagination::bootstrap-5') }}</div>@endif
@endsection
