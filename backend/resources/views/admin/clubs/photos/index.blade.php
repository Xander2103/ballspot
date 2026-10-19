@extends('admin.layout')

@section('title', 'Photo review')

@php
    $statusLabels = \App\Http\Controllers\Admin\Clubs\ClubPhotoController::statusLabels();
    $statusClass  = \App\Http\Controllers\Admin\Clubs\ClubPhotoController::statusClasses();
    $open = $counts['pending'] + $counts['in_review'] + $counts['in_preparation'];
    $statusFilters = [
        'open'           => ['Open', $open],
        'pending'        => ['Pending', $counts['pending']],
        'in_review'      => ['In review', $counts['in_review']],
        'in_preparation' => ['In preparation', $counts['in_preparation']],
        'converted'      => ['Converted', $counts['converted']],
        'rejected'       => ['Rejected', $counts['rejected']],
        'all'            => ['All', array_sum($counts)],
    ];
    $currentStatus = array_key_exists($filters['status'], $statusFilters) ? $filters['status'] : 'open';
    $me = auth()->id();
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Photo review</h1>
        <p class="text-muted small mb-0">Photos submitted for clubs, oldest first. Prepare = download the original, remove the ball offline, upload the result and mark the ball → private club challenge.</p>
    </div>
</div>

<ul class="nav nav-pills small mb-3" data-section="status-filters">
    @foreach($statusFilters as $value => [$label, $count])
        <li class="nav-item">
            <a class="nav-link py-1 px-2 {{ $currentStatus === $value ? 'active' : '' }}"
               href="{{ route('admin.club-photos.index', array_filter(['status' => $value, 'club' => $filters['club'], 'assignee' => $filters['assignee']])) }}">
                {{ $label }} <span class="badge {{ $currentStatus === $value ? 'bg-light text-dark' : 'badge-soft' }}">{{ $count }}</span>
            </a>
        </li>
    @endforeach
</ul>

<form method="GET" action="{{ route('admin.club-photos.index') }}" class="row g-2 align-items-end mb-3" data-section="filters">
    <input type="hidden" name="status" value="{{ $currentStatus }}">
    <div class="col-sm-4 col-md-3">
        <label for="f-club" class="form-label small mb-1">Club</label>
        <select id="f-club" name="club" class="form-select form-select-sm">
            <option value="">Any club</option>
            @foreach($clubOptions as $opt)
                <option value="{{ $opt->id }}" {{ (string) $filters['club'] === (string) $opt->id ? 'selected' : '' }}>{{ $opt->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-sm-4 col-md-3">
        <label for="f-assignee" class="form-label small mb-1">Assignee</label>
        <select id="f-assignee" name="assignee" class="form-select form-select-sm">
            <option value="">Anyone</option>
            <option value="me" {{ $filters['assignee'] === 'me' ? 'selected' : '' }}>Me</option>
            <option value="unassigned" {{ $filters['assignee'] === 'unassigned' ? 'selected' : '' }}>Unassigned</option>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-secondary btn-sm">Filter</button>
        <a href="{{ route('admin.club-photos.index') }}" class="btn btn-link btn-sm">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr><th>Photo</th><th>Club · submitted</th><th>Size</th><th>Note</th><th>Status</th><th>Assignee</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            @forelse($submissions as $s)
                @php $isOpen = $s->isOpen(); @endphp
                <tr data-submission="{{ $s->id }}">
                    <td>
                        <a href="{{ route('admin.club-photos.prepare', $s) }}" title="Open photo #{{ $s->id }}">
                            @if($s->path && \App\Services\Clubs\ClubMediaService::isSafePath($s->path))
                                <img src="{{ $media->signedUrl($s->path) }}" alt="Photo submission {{ $s->id }}" class="thumb" loading="lazy">
                            @else
                                <span class="thumb-missing text-muted">no file</span>
                            @endif
                        </a>
                    </td>
                    <td class="small">
                        <a href="{{ route('admin.clubs.show', ['club' => $s->club_id, 'tab' => 'photos']) }}" class="fw-semibold">{{ $s->club?->name ?? 'Club #' . $s->club_id }}</a>
                        <div class="text-muted" style="font-size:.75rem">
                            #{{ $s->id }} · {{ $s->source === 'club_upload' ? 'uploaded by club admin' : 'uploaded by BallPicker' }}@if($s->uploadedBy) ({{ $s->uploadedBy->username }})@endif
                            · {{ $s->created_at?->format('Y-m-d H:i') }} · <span title="Age">{{ $s->created_at?->diffForHumans(null, true) }} old</span>
                        </div>
                    </td>
                    <td class="small text-nowrap">{{ \App\Http\Controllers\Admin\Clubs\ClubPhotoController::humanSize($s->bytes) }}<div class="text-muted" style="font-size:.75rem">{{ $s->width }}×{{ $s->height }}</div>
                        @if($s->rights_confirmed_at)<span class="text-success" style="font-size:.75rem" title="Rights confirmed {{ $s->rights_confirmed_at->format('Y-m-d') }}">rights ✓</span>@else<span class="text-muted" style="font-size:.75rem">rights —</span>@endif
                    </td>
                    <td class="small" style="max-width: 16rem;">
                        @if($s->club_note)Note: "{{ \Illuminate\Support\Str::limit($s->club_note, 90) }}"@else<span class="text-muted">—</span>@endif
                        @if($s->rejection_reason)<div class="text-danger" style="font-size:.75rem">Rejected: {{ \Illuminate\Support\Str::limit($s->rejection_reason, 90) }}</div>@endif
                    </td>
                    <td>
                        <span class="badge {{ $statusClass[$s->status->value] ?? 'badge-soft' }}">{{ $statusLabels[$s->status->value] ?? $s->status->value }}</span>
                        @if($s->challenge)<div style="font-size:.75rem"><a href="{{ route('challenges.edit', $s->challenge) }}">{{ \Illuminate\Support\Str::limit($s->challenge->title, 30) }}</a></div>@endif
                    </td>
                    <td class="small">{{ $s->assignedTo?->username ?? '—' }}@if($s->assigned_to_user_id === $me) <span class="badge badge-soft">me</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if($isOpen && $s->assigned_to_user_id !== $me)
                            <form method="POST" action="{{ route('admin.club-photos.assign', $s) }}" class="d-inline">@csrf<button class="btn btn-outline-secondary btn-sm">Assign to me</button></form>
                        @endif
                        <a href="{{ route('admin.club-photos.prepare', $s) }}" class="btn btn-sm {{ $isOpen ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $isOpen ? 'Prepare challenge →' : 'View' }}</a>
                        @if($isOpen)
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#photo-reject-modal"
                                    data-url="{{ route('admin.club-photos.reject', $s) }}" data-photo="#{{ $s->id }} · {{ $s->club?->name }}">Reject…</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    @if($currentStatus === 'open' && !$filters['club'] && $filters['assignee'] === '')
                        No open photos — the review queue is empty. Clubs upload photos from the Club Portal; you can also upload on a club's Photos tab.
                    @else
                        No photos match these filters.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($submissions->hasPages())<div class="mt-3">{{ $submissions->links('pagination::bootstrap-5') }}</div>@endif

@include('admin.clubs.photos._reject_modal')
@endsection
