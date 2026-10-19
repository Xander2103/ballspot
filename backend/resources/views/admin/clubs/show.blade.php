@extends('admin.layout')

@section('title', $club->name)

@php
    $typeLabels = ['sports_club' => 'Sports club', 'school' => 'School', 'company' => 'Company', 'community' => 'Community', 'other' => 'Other'];
    $tabs = [
        'overview'     => ['label' => 'Overview',     'count' => null],
        'members'      => ['label' => 'Members',      'count' => $tabCounts['members']],
        'codes'        => ['label' => 'Invite codes', 'count' => $tabCounts['codes']],
        'packs'        => ['label' => 'Packs',        'count' => $tabCounts['packs']],
        'competitions' => ['label' => 'Competitions', 'count' => $tabCounts['competitions']],
        'photos'       => ['label' => 'Photos',       'count' => $tabCounts['photos']],
        'settings'     => ['label' => 'Settings',     'count' => null],
        'audit'        => ['label' => 'Audit',        'count' => null],
    ];
    $status = $club->status->value;
@endphp

@section('content')
<div class="mb-2"><a href="{{ route('admin.clubs.index') }}" class="text-muted small">&larr; Back to Clubs</a></div>

{{-- Identity header --}}
<div class="d-flex flex-wrap align-items-start gap-3 mb-3" data-section="club-header">
    @include('admin.clubs._logo', ['club' => $club, 'size' => 'lg'])
    <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0">{{ $club->name }}</h1>
            @include('admin.clubs._status', ['club' => $club])
            @if(!$club->join_enabled)<span class="badge badge-soft" title="Invite codes cannot be used">Joining off</span>@endif
        </div>
        <div class="text-muted small mt-1">
            {{ collect([$club->city, $club->country_code])->filter()->join(', ') ?: 'No location' }}
            · {{ $typeLabels[$club->club_type->value] ?? $club->club_type->value }}
            @if($club->sport) · {{ $club->sport->emoji }} {{ $club->sport->name }}@endif
            · created {{ $club->created_at?->format('Y-m-d') }}@if($club->createdBy) by {{ $club->createdBy->username }}@endif
        </div>
        <div class="text-muted small font-monospace">{{ $club->slug }} · #{{ $club->id }} · {{ $club->public_id }}@if($club->external_ref) · ref {{ $club->external_ref }}@endif</div>
        @if($status === 'suspended' && $club->suspension_reason)
            <div class="small mt-1"><span class="badge badge-suspended">Suspended {{ $club->suspended_at?->format('Y-m-d') }}</span> {{ $club->suspension_reason }}</div>
        @elseif($status === 'archived' && $club->archived_reason)
            <div class="small mt-1"><span class="badge badge-archived">Archived {{ $club->archived_at?->format('Y-m-d') }}</span> {{ $club->archived_reason }}</div>
        @endif
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.clubs.edit', $club) }}" class="btn btn-outline-secondary btn-sm">Edit details</a>
        @if($status === 'active')
            <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="suspend" data-url="{{ route('admin.clubs.suspend', $club) }}" data-club="{{ $club->name }}">Suspend</button>
        @else
            <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="reactivate" data-url="{{ route('admin.clubs.reactivate', $club) }}" data-club="{{ $club->name }}">Reactivate</button>
        @endif
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More club actions">More</button>
            <ul class="dropdown-menu dropdown-menu-end">
                @if($status !== 'archived')
                    <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="archive" data-url="{{ route('admin.clubs.archive', $club) }}" data-club="{{ $club->name }}">Archive…</button></li>
                @endif
                @if($hasHistory)
                    <li><span class="dropdown-item-text small text-muted" style="max-width: 18rem;">Delete is not possible: this club has history. Archive it instead.</span></li>
                @else
                    <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="delete" data-url="{{ route('admin.clubs.destroy', $club) }}" data-club="{{ $club->name }}">Delete permanently…</button></li>
                @endif
            </ul>
        </div>
    </div>
</div>

@if($club->needs_owner && $status !== 'archived')
<div class="alert alert-warning py-2 small d-flex justify-content-between align-items-center gap-2">
    <span><strong>This club has no active owner.</strong> Platform admins can manage it; add a member as owner or transfer ownership on the Members tab.</span>
    <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'members']) }}" class="btn btn-sm btn-outline-dark text-nowrap">Go to members</a>
</div>
@endif

{{-- Tabs --}}
<ul class="nav nav-tabs club-tabs mb-3" role="tablist" data-section="club-tabs">
    @foreach($tabs as $key => $t)
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
                {{ $t['label'] }}@if($t['count'] !== null) <span class="badge bg-light text-dark border ms-1">{{ $t['count'] }}</span>@endif
            </a>
        </li>
    @endforeach
</ul>

<div data-section="tab-{{ $tab }}">
    @include('admin.clubs.tabs.' . $tab)
</div>

@include('admin.clubs._status_modals')
@endsection
