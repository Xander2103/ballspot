@extends('admin.layout')

@section('title', 'Preview: ' . $challenge->title)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/admin/challenges" class="text-muted small text-decoration-none">← Challenges</a>
        <h1 class="h3 mb-0 mt-1">Preview: {{ $challenge->title }}</h1>
    </div>
    <a href="/admin/challenges/{{ $challenge->id }}/edit" class="btn btn-outline-secondary btn-sm">Edit</a>
</div>

{{-- Readiness summary --}}
<div class="mb-4">
    @if($challenge->isReadyForDaily())
        <span class="badge bg-success fs-6 px-3 py-2">Ready for Daily Challenge</span>
    @elseif($challenge->isReady())
        <span class="badge bg-warning text-dark fs-6 px-3 py-2">Ready — not active (cannot use as daily)</span>
    @else
        @php
            $missing = [];
            if(empty($challenge->hidden_image_path)) $missing[] = 'hidden image';
            if($challenge->ball_x_ratio === null) $missing[] = 'ball position';
            if(empty($challenge->title)) $missing[] = 'title';
            if(!$challenge->sport_id) $missing[] = 'sport';
        @endphp
        <span class="badge bg-danger fs-6 px-3 py-2">Incomplete — missing: {{ implode(', ', $missing) }}</span>
    @endif

    @if($challenge->isDemoContent())
        <span class="badge bg-warning text-dark ms-2 fs-6 px-3 py-2">Demo placeholder content</span>
    @endif
</div>

<div class="row g-4">
    {{-- Hidden image (what the player sees) --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Hidden image <span class="text-muted fw-normal small">(what the player sees)</span></div>
            <div class="card-body p-2">
                @if($challenge->hidden_image_path)
                    <div class="position-relative" style="user-select:none;">
                        <img src="{{ asset('storage/' . $challenge->hidden_image_path) }}"
                             alt="Hidden image" class="img-fluid rounded w-100"
                             id="hiddenImg">
                        {{-- Ball marker shown at correct position --}}
                        <div id="ballMarker" class="position-absolute"
                             style="width:24px;height:24px;border-radius:50%;
                                    background:rgba(255,60,60,0.85);
                                    border:3px solid #fff;
                                    box-shadow:0 2px 8px rgba(0,0,0,.5);
                                    transform:translate(-50%,-50%);
                                    pointer-events:none;
                                    left:{{ $challenge->ball_x_ratio * 100 }}%;
                                    top:{{ $challenge->ball_y_ratio * 100 }}%;">
                        </div>
                    </div>
                @else
                    <div class="text-center text-danger py-5">No hidden image uploaded.</div>
                @endif
            </div>
            <div class="card-footer text-muted small">
                Ball position: {{ round($challenge->ball_x_ratio * 100, 1) }}% from left,
                {{ round($challenge->ball_y_ratio * 100, 1) }}% from top
            </div>
        </div>
    </div>

    {{-- Reveal image --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Reveal image <span class="text-muted fw-normal small">(shown after guessing)</span></div>
            <div class="card-body p-2">
                @if($challenge->original_image_path)
                    <img src="{{ asset('storage/' . $challenge->original_image_path) }}"
                         alt="Reveal image" class="img-fluid rounded w-100">
                @else
                    <div class="text-center text-muted py-5">
                        No reveal image uploaded.<br>
                        <span class="small">Players will see the hidden image as the reveal.</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Challenge details --}}
<div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold">Challenge details</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Title</dt>
            <dd class="col-sm-9">{{ $challenge->title }}</dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge badge-{{ $challenge->status }}">{{ ucfirst($challenge->status) }}</span>
            </dd>

            <dt class="col-sm-3">Difficulty</dt>
            <dd class="col-sm-9">
                <span class="badge badge-{{ $challenge->difficulty }}">{{ ucfirst($challenge->difficulty) }}</span>
            </dd>

            <dt class="col-sm-3">Category</dt>
            <dd class="col-sm-9">{{ $challenge->category?->name ?? '—' }}</dd>

            <dt class="col-sm-3">Created</dt>
            <dd class="col-sm-9">{{ $challenge->created_at->format('d M Y H:i') }}</dd>
        </dl>
    </div>
</div>

{{-- Set as daily --}}
@if($challenge->isReadyForDaily())
<div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold">Set as daily challenge</div>
    <div class="card-body">
        <form action="/admin/challenges/{{ $challenge->id }}/set-as-daily" method="POST" class="d-flex gap-2 align-items-end">
            @csrf
            <div>
                <label class="form-label small mb-1">Date</label>
                <input type="date" name="date" value="{{ date('Y-m-d') }}"
                       class="form-control form-control-sm" required style="width:160px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Set as Daily</button>
        </form>
    </div>
</div>
@endif
@endsection
