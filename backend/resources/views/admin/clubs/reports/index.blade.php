@extends('admin.layout')

@section('title', 'Club reports')

@section('content')
@php
    $statusBadge = ['open' => 'badge-soft-warn', 'reviewed' => 'badge-soft', 'dismissed' => 'badge-archived', 'actioned' => 'badge-soft-ok'];
@endphp
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Club reports <span class="badge rounded-pill bg-danger align-middle" data-section="open-count" title="open reports">{{ $openCount }}</span></h1>
        <div class="text-muted small">Reports members filed from the app about a club, a competition or another member. Reporter identities are visible to platform admins only (never in the club portal).</div>
    </div>
</div>

@if($filterClub)
    <div class="alert alert-light border py-2 small">Showing reports of <a href="{{ route('admin.clubs.show', $filterClub) }}">{{ $filterClub->name }}</a> only.</div>
@endif

<form method="GET" action="{{ route('admin.club-reports.index') }}" class="row g-2 align-items-end mb-3" data-section="report-filters">
    <div class="col-6 col-md-2">
        <label for="r-status" class="small text-muted mb-1">Status</label>
        <select id="r-status" name="status" class="form-select form-select-sm">
            <option value="all" {{ $filters['status'] === '' ? 'selected' : '' }}>All</option>
            @foreach(\App\Models\ClubContentReport::STATUSES as $s)
                <option value="{{ $s }}" {{ $filters['status'] === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="r-reason" class="small text-muted mb-1">Reason</label>
        <select id="r-reason" name="reason" class="form-select form-select-sm">
            <option value="">Any</option>
            @foreach(\App\Models\ClubContentReport::REASONS as $r)
                <option value="{{ $r }}" {{ $filters['reason'] === $r ? 'selected' : '' }}>{{ ucfirst($r) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="r-club" class="small text-muted mb-1">Club ID</label>
        <input id="r-club" type="text" inputmode="numeric" name="club" value="{{ $filters['club'] }}" class="form-control form-control-sm" placeholder="any">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <a href="{{ route('admin.club-reports.index') }}" class="btn btn-link btn-sm">Reset</a>
    </div>
</form>

<div class="card shadow-sm" data-section="reports">
    <div class="table-responsive">
        <table class="table table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Filed</th>
                    <th>Club</th>
                    <th>Subject</th>
                    <th>Reason</th>
                    <th>Reporter</th>
                    <th>Status</th>
                    <th style="min-width: 18rem">Resolve</th>
                </tr>
            </thead>
            <tbody>
            @forelse($reports as $report)
                @php $subject = $subjects[$report->id] ?? ['label' => ucfirst($report->subject_type), 'competition' => null, 'member' => null]; @endphp
                <tr data-report="{{ $report->id }}">
                    <td class="text-muted">{{ $report->id }}</td>
                    <td class="small text-nowrap">{{ $report->created_at?->format('Y-m-d H:i') }}</td>
                    <td>@if($report->club)<a class="club-name" href="{{ route('admin.clubs.show', $report->club) }}">{{ $report->club->name }}</a>@else<span class="text-muted">—</span>@endif</td>
                    <td class="small">
                        <span class="badge badge-soft">{{ $subject['label'] }}</span>
                        @if($report->subject_type === 'competition')
                            @if($subject['competition'])
                                <a href="{{ route('admin.clubs.competitions.show', [$report->club_id, $subject['competition']->id]) }}">{{ $subject['competition']->name }}</a>
                            @else
                                <span class="text-muted">competition {{ $report->subject_ref }} (gone)</span>
                            @endif
                        @elseif($report->subject_type === 'member')
                            @if($subject['member']?->user)
                                <a href="{{ route('admin.clubs.show', [$report->club_id, 'tab' => 'members', 'q' => $subject['member']->user->username]) }}">{{ $subject['member']->user->username }}</a>
                                @if($subject['member']->status->value !== 'active')<span class="badge badge-archived">{{ $subject['member']->status->value }}</span>@endif
                            @else
                                <span class="text-muted">user #{{ $report->subject_ref }}</span>
                            @endif
                        @else
                            @if($report->club)<a href="{{ route('admin.clubs.show', $report->club) }}">club profile</a>@endif
                        @endif
                    </td>
                    <td class="small">
                        <strong>{{ ucfirst($report->reason) }}</strong>
                        @if($report->details)<div class="text-muted text-break" style="max-width: 22rem">{{ $report->details }}</div>@endif
                    </td>
                    <td class="small">{{ $report->reporter?->username ?? 'deleted user' }}</td>
                    <td class="small">
                        <span class="badge {{ $statusBadge[$report->status] ?? 'badge-soft' }}">{{ $report->status }}</span>
                        @if($report->reviewed_at)<div class="text-muted">{{ $report->reviewedBy?->username ?? '—' }} · {{ $report->reviewed_at->format('Y-m-d H:i') }}</div>@endif
                        @if($report->admin_note)<div class="text-muted text-break">{{ $report->admin_note }}</div>@endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('admin.club-reports.resolve', $report) }}" class="d-flex flex-column gap-1" data-section="resolve">
                            @csrf
                            <input type="text" name="admin_note" maxlength="1000" class="form-control form-control-sm" placeholder="Note (optional)" aria-label="Admin note" value="{{ $report->admin_note }}">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Resolve report {{ $report->id }}">
                                <button type="submit" name="status" value="reviewed" class="btn btn-outline-secondary">Mark reviewed</button>
                                <button type="submit" name="status" value="dismissed" class="btn btn-outline-secondary">Dismiss</button>
                                <button type="submit" name="status" value="actioned" class="btn btn-outline-primary">Actioned</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No reports{{ $filters['status'] !== '' ? ' with status ' . $filters['status'] : '' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($reports->hasPages())<div class="mt-3">{{ $reports->links('pagination::bootstrap-5') }}</div>@endif
@endsection
