@extends('club-portal.layout')

@section('title', __('club_portal.home.title'))

@section('content')
<h1 class="h4 mb-1">{{ __('club_portal.home.heading') }}</h1>
<p class="text-muted">{{ __('club_portal.home.intro') }}</p>
<div class="row g-3" data-section="club-list">
    @forelse($clubs as $c)
        <div class="col-sm-6 col-lg-4">
            <a href="{{ route('club-portal.dashboard', $c) }}" class="portal-card d-block p-3 text-decoration-none text-reset h-100">
                <div class="d-flex align-items-center gap-2">
                    @if($c->logoUrl())<img src="{{ $c->logoUrl() }}" alt="" width="40" height="40" class="rounded">@endif
                    <div>
                        <div class="fw-bold">{{ $c->name }}</div>
                        <div class="small text-muted">{{ $c->city }}@if($c->city) · @endif{{ __('club_portal.home.members', ['count' => (int) $c->members_count]) }}</div>
                    </div>
                </div>
                <div class="mt-2">
                    <span class="badge badge-role-{{ $c->portal_role->value }}">{{ __('club_portal.roles.' . $c->portal_role->value) }}</span>
                    @if($c->isSuspended())<span class="badge bg-warning text-dark">{{ __('club_portal.club_status.suspended') }}</span>@endif
                </div>
            </a>
        </div>
    @empty
        <div class="col-12"><div class="alert alert-info">{{ __('club_portal.home.empty') }}</div></div>
    @endforelse
</div>
@endsection
