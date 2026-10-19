@extends('admin.layout')

@section('title', 'Photo #' . $submission->id . ' · ' . $club->name)

@php
    $statusLabels = \App\Http\Controllers\Admin\Clubs\ClubPhotoController::statusLabels();
    $statusClass  = \App\Http\Controllers\Admin\Clubs\ClubPhotoController::statusClasses();
    $status = $submission->status->value;
    $isOpen = $submission->isOpen();
    $me = auth()->id();
    $football = $sports->firstWhere('slug', 'football')?->id ?? $club->sport_id;
@endphp

@section('content')
<div class="mb-2"><a href="{{ route('admin.club-photos.index') }}" class="text-muted small">&larr; Back to Photo review</a></div>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">Photo #{{ $submission->id }}</h1>
    <span class="badge {{ $statusClass[$status] ?? 'badge-soft' }}" data-status="{{ $status }}">{{ $statusLabels[$status] ?? $status }}</span>
    <span class="badge badge-club">Club photo</span>
    @if($submission->rights_confirmed_at)<span class="text-success small" title="Rights confirmed {{ $submission->rights_confirmed_at->format('Y-m-d H:i') }}">rights ✓</span>@else<span class="text-muted small">rights not confirmed</span>@endif
</div>
<p class="text-muted small mb-3">
    <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'photos']) }}">{{ $club->name }}</a>
    · {{ $submission->source === 'club_upload' ? 'uploaded by club admin' : 'uploaded by BallPicker' }}@if($submission->uploadedBy) ({{ $submission->uploadedBy->username }})@endif
    · {{ $submission->created_at?->format('Y-m-d H:i') }} ({{ $submission->created_at?->diffForHumans() }})
    · {{ \App\Http\Controllers\Admin\Clubs\ClubPhotoController::humanSize($submission->bytes) }} {{ $submission->width }}×{{ $submission->height }}
    · assignee: {{ $submission->assignedTo?->username ?? 'nobody' }}
</p>

@if($status === 'converted')
    <div class="alert alert-success" data-section="converted">
        <strong>Converted.</strong>
        @if($submission->challenge)
            Challenge <a href="{{ route('challenges.edit', $submission->challenge) }}" class="alert-link">“{{ $submission->challenge->title }}”</a>
            (<a href="{{ route('admin.challenges.preview', $submission->challenge) }}" class="alert-link">preview</a>,
            <a href="/admin/challenges?club={{ $club->id }}" class="alert-link">club challenges</a>)
        @else
            The challenge was deleted.
        @endif
        @foreach($challengePacks as $p)
            · in pack <a href="{{ route('admin.packs.edit', $p->id) }}" class="alert-link">“{{ $p->name }}”</a>
        @endforeach
        · reviewed by {{ $submission->reviewedBy?->username ?? '—' }} {{ $submission->reviewed_at?->format('Y-m-d H:i') }}.
        <div class="small mt-1"><a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'packs']) }}" class="alert-link">Club packs →</a></div>
    </div>
@elseif($status === 'rejected')
    <div class="alert alert-secondary" data-section="rejected">
        <strong>Rejected</strong> by {{ $submission->reviewedBy?->username ?? '—' }} {{ $submission->reviewed_at?->format('Y-m-d H:i') }}.
        Reason (visible to the club): {{ $submission->rejection_reason }}
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h2 class="h6 mb-0">Original photo</h2>
                    <a href="{{ route('admin.club-photos.download', $submission) }}" class="btn btn-outline-primary btn-sm" data-action="download">Download original</a>
                </div>
                @if($photoUrl)
                    <div id="original-picker" class="image-picker-container {{ $isOpen ? '' : 'no-pick' }}">
                        <img id="original-preview" src="{{ $photoUrl }}" alt="Original photo {{ $submission->id }}">
                        <div id="original-marker" class="ball-marker" style="display:none;"></div>
                    </div>
                    @if($isOpen)<p class="text-muted small mt-1 mb-0">The ball is visible here: click it to set the ball position.</p>@endif
                @else
                    <p class="text-muted mb-0">The photo file is missing.</p>
                @endif
                @if($submission->club_note)
                    <p class="small mt-2 mb-0"><strong>Club note:</strong> "{{ $submission->club_note }}"</p>
                @endif
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6">History</h2>
                <ul class="list-unstyled small mb-0" data-section="history">
                    @forelse($history as $event)
                        <li class="mb-1"><span class="text-muted">{{ $event->created_at?->format('Y-m-d H:i') }}</span> · {{ $event->label() }} · {{ $event->actor?->username ?? 'system' }}@if(!empty($event->metadata['reason'])) — {{ $event->metadata['reason'] }}@endif</li>
                    @empty
                        <li class="text-muted">No events yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        @if($isOpen)
            <div class="card shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2" data-section="actions">
                    @if($submission->assigned_to_user_id !== $me)
                        <form method="POST" action="{{ route('admin.club-photos.assign', $submission) }}">@csrf<button class="btn btn-outline-secondary btn-sm">Assign to me</button></form>
                    @endif
                    @if(in_array('in_review', $allowedNext, true))
                        <form method="POST" action="{{ route('admin.club-photos.in-review', $submission) }}">@csrf<button class="btn btn-outline-secondary btn-sm">Mark in review</button></form>
                    @endif
                    @if(in_array('in_preparation', $allowedNext, true))
                        <form method="POST" action="{{ route('admin.club-photos.start-preparation', $submission) }}">@csrf<button class="btn btn-outline-primary btn-sm">Start preparation</button></form>
                    @endif
                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#photo-reject-modal"
                            data-url="{{ route('admin.club-photos.reject', $submission) }}" data-photo="#{{ $submission->id }} · {{ $club->name }}">Reject…</button>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-1">Create club challenge</h2>
                    <p class="text-muted small mb-3">Saved privately for {{ $club->name }} (usage pool <code>club</code>): never Daily, tournaments or public packs. The original above becomes the reveal image.</p>
                    <form method="POST" action="{{ route('admin.club-photos.convert', $submission) }}" enctype="multipart/form-data" id="convert-form">
                        @csrf
                        <div class="mb-3">
                            <label for="hidden_image" class="form-label fw-semibold">Ball-removed image <span class="text-danger">*</span></label>
                            <input type="file" id="hidden_image" name="hidden_image" accept="image/jpeg,image/png,image/webp" required
                                   class="form-control @error('hidden_image') is-invalid @enderror">
                            <div class="form-text">The same frame with the ball removed (JPEG/PNG/WebP). Re-encoded, EXIF removed.</div>
                            @error('hidden_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div id="hidden-picker-wrap" style="display:none" class="mb-3">
                            <div id="hidden-picker" class="image-picker-container">
                                <img id="hidden-preview" src="" alt="Ball-removed preview">
                                <div id="hidden-marker" class="ball-marker" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="row g-2 mb-1">
                            <div class="col-6">
                                <label for="ball_x_ratio" class="form-label fw-semibold">Ball X <span class="text-danger">*</span></label>
                                <input type="number" id="ball_x_ratio" name="ball_x_ratio" min="0" max="1" step="0.001" required
                                       value="{{ old('ball_x_ratio') }}" class="form-control form-control-sm @error('ball_x_ratio') is-invalid @enderror">
                                @error('ball_x_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <label for="ball_y_ratio" class="form-label fw-semibold">Ball Y <span class="text-danger">*</span></label>
                                <input type="number" id="ball_y_ratio" name="ball_y_ratio" min="0" max="1" step="0.001" required
                                       value="{{ old('ball_y_ratio') }}" class="form-control form-control-sm @error('ball_y_ratio') is-invalid @enderror">
                                @error('ball_y_ratio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <p id="coords-display" class="text-muted small mb-3">Ball position: not set — click the ball on the original.</p>

                        <div class="mb-3">
                            <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" id="title" name="title" maxlength="255" required value="{{ old('title') }}"
                                   class="form-control form-control-sm @error('title') is-invalid @enderror">
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="difficulty" class="form-label fw-semibold">Difficulty <span class="text-danger">*</span></label>
                                <select id="difficulty" name="difficulty" required class="form-select form-select-sm @error('difficulty') is-invalid @enderror">
                                    @foreach(['easy', 'medium', 'hard'] as $d)
                                        <option value="{{ $d }}" {{ old('difficulty', 'medium') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                                    @endforeach
                                </select>
                                @error('difficulty')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <label for="status" class="form-label fw-semibold">Challenge status</label>
                                <select id="status" name="status" class="form-select form-select-sm">
                                    <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="draft" {{ old('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="challenge_category_id" class="form-label fw-semibold">Category</label>
                                <select id="challenge_category_id" name="challenge_category_id" class="form-select form-select-sm">
                                    <option value="">— Uncategorised —</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" {{ (string) old('challenge_category_id') === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="sport_id" class="form-label fw-semibold">Sport</label>
                                <select id="sport_id" name="sport_id" class="form-select form-select-sm">
                                    @foreach($sports as $sport)
                                        <option value="{{ $sport->id }}" {{ (string) old('sport_id', $football) === (string) $sport->id ? 'selected' : '' }}>{{ $sport->emoji }} {{ $sport->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="tags" class="form-label fw-semibold">Tags <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" id="tags" name="tags" maxlength="500" value="{{ old('tags') }}" class="form-control form-control-sm" placeholder="e.g. derby, corner kick">
                        </div>
                        <div class="mb-3">
                            <label for="pack_id" class="form-label fw-semibold">Add to club pack</label>
                            <select id="pack_id" name="pack_id" class="form-select form-select-sm">
                                <option value="">Don't add to a pack</option>
                                @foreach($packs as $pack)
                                    <option value="{{ $pack->id }}" {{ (string) old('pack_id') === (string) $pack->id ? 'selected' : '' }}>{{ $pack->name }} ({{ $pack->status }}, {{ $pack->challenges_count }} challenges)</option>
                                @endforeach
                            </select>
                            <div class="form-text">Only {{ $club->name }}'s own packs. @if($packs->isEmpty())No packs yet — <a href="{{ route('admin.clubs.packs.create', $club) }}">create one</a>.@endif</div>
                        </div>
                        <div class="mb-3">
                            <label for="admin_note" class="form-label fw-semibold">Internal note <span class="text-muted fw-normal">(BallPicker only)</span></label>
                            <textarea id="admin_note" name="admin_note" rows="2" maxlength="2000" class="form-control form-control-sm">{{ old('admin_note', $submission->admin_note) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Create club challenge</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>

@include('admin.clubs.photos._reject_modal')

<style>
.image-picker-container { position: relative; display: inline-block; cursor: crosshair; max-width: 100%; border-radius: 6px; overflow: hidden; border: 2px solid #dee2e6; }
.image-picker-container.no-pick { cursor: default; }
.image-picker-container img { max-width: 100%; display: block; }
.ball-marker { position: absolute; width: 22px; height: 22px; border-radius: 50%; background: #dc3545; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.5); transform: translate(-50%, -50%); pointer-events: none; }
</style>

@if($isOpen)
@push('scripts')
<script>
(function () {
    // Same marking behaviour as the challenge form: 0..1 ratios, 3 decimals.
    var xInput = document.getElementById('ball_x_ratio');
    var yInput = document.getElementById('ball_y_ratio');
    var coords = document.getElementById('coords-display');
    if (!xInput || !yInput) return;

    function round3(v) { return Math.round(v * 1000) / 1000; }

    function apply(x, y) {
        x = round3(x); y = round3(y);
        xInput.value = x; yInput.value = y;
        coords.textContent = 'Ball position: x=' + x + ' (' + Math.round(x * 100) + '%), y=' + y + ' (' + Math.round(y * 100) + '%)';
        ['original-marker', 'hidden-marker'].forEach(function (id) {
            var m = document.getElementById(id);
            if (!m) return;
            m.style.left = (x * 100) + '%';
            m.style.top = (y * 100) + '%';
            m.style.display = 'block';
        });
    }

    function attach(pickerId, imgId) {
        var picker = document.getElementById(pickerId);
        var img = document.getElementById(imgId);
        if (!picker || !img) return;
        picker.addEventListener('click', function (e) {
            var r = img.getBoundingClientRect();
            apply(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)));
        });
    }

    [xInput, yInput].forEach(function (input) {
        input.addEventListener('input', function () {
            var x = parseFloat(String(xInput.value).replace(',', '.'));
            var y = parseFloat(String(yInput.value).replace(',', '.'));
            if (!isNaN(x) && !isNaN(y)) apply(Math.min(1, Math.max(0, x)), Math.min(1, Math.max(0, y)));
        });
    });

    var file = document.getElementById('hidden_image');
    if (file) file.addEventListener('change', function () {
        var f = this.files[0];
        var wrap = document.getElementById('hidden-picker-wrap');
        if (!f) { wrap.style.display = 'none'; return; }
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('hidden-preview').src = e.target.result;
            wrap.style.display = 'block';
            if (xInput.value !== '' && yInput.value !== '') apply(parseFloat(xInput.value), parseFloat(yInput.value));
        };
        reader.readAsDataURL(f);
    });

    attach('original-picker', 'original-preview');
    attach('hidden-picker', 'hidden-preview');
    if (xInput.value !== '' && yInput.value !== '') apply(parseFloat(xInput.value), parseFloat(yInput.value));
}());
</script>
@endpush
@endif
@endsection
