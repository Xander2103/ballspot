@extends('admin.layout')

@section('title', 'Schedule Daily Challenges')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.daily.index') }}" class="text-muted small">&larr; Back to Daily Challenges</a>
</div>
<h1 class="h3 mb-4">Schedule Daily Challenges</h1>

<form action="{{ route('admin.daily.store') }}" method="POST">
    @csrf
    {{-- Carries the admin's click order; checkboxes alone would post in DOM order. --}}
    <input type="hidden" id="selection_order" name="selection_order" value="{{ old('selection_order') }}">

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <label class="form-label fw-semibold mb-0">
                    Challenges <span class="text-danger">*</span>
                </label>
                @if($selectable->isNotEmpty())
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-all">Select all</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-none">Clear</button>
                </div>
                @endif
            </div>

            <p class="form-text mt-0 mb-3">
                Select multiple challenges. They will be scheduled automatically starting from the first free date,
                one per day, in the order you tick them. Dates that already have a daily challenge are skipped.
            </p>

            @error('challenge_ids')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
            @error('challenge_ids.*')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

            @if($selectable->isEmpty())
                <div class="alert alert-warning mb-0">
                    No challenges are available for scheduling. A challenge must be active, have a hidden image and a
                    ball position, be in the Daily or General pool, and must not have been used as a daily challenge before.
                </div>
            @else
                <div id="challenge-checklist" class="border rounded p-2" style="max-height: 320px; overflow-y: auto;">
                    @foreach($selectable as $challenge)
                    <div class="form-check py-1 border-bottom">
                        <input class="form-check-input challenge-checkbox" type="checkbox"
                               name="challenge_ids[]" value="{{ $challenge->id }}"
                               id="challenge-{{ $challenge->id }}"
                               {{ in_array($challenge->id, (array) old('challenge_ids', [])) ? 'checked' : '' }}>
                        <label class="form-check-label d-flex justify-content-between" for="challenge-{{ $challenge->id }}">
                            <span>
                                <span class="order-badge badge bg-primary me-1 d-none"></span>
                                {{ $challenge->title }}
                                <span class="badge bg-pool-{{ $challenge->usage_pool }}">{{ ucfirst($challenge->usage_pool) }}</span>
                                @if($challenge->isDemoContent())
                                    <span class="badge bg-warning text-dark">Demo</span>
                                @endif
                            </span>
                            <span class="text-muted small">
                                {{ ucfirst($challenge->difficulty) }}
                                @if(!$challenge->original_image_path) &middot; no reveal @endif
                            </span>
                        </label>
                    </div>
                    @endforeach
                </div>
                <div class="form-text">
                    {{ $selectable->count() }} challenge(s) available &middot;
                    <span id="selected-count">0</span> selected
                </div>
            @endif
        </div>
    </div>

    <div class="card shadow-sm mb-4" style="max-width: 580px;">
        <div class="card-body">
            <div class="mb-3">
                <label for="start_date" class="form-label fw-semibold">Start date</label>
                <input type="date" id="start_date" name="start_date"
                       class="form-control @error('start_date') is-invalid @enderror"
                       value="{{ old('start_date') }}">
                <div class="form-text">
                    Leave empty to start at the first free date ({{ $nextFreeDate }}).
                </div>
                @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label for="status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    @foreach(['scheduled','active','archived'] as $s)
                    <option value="{{ $s }}" {{ old('status', 'active') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <div class="form-text">Applied to every challenge in this batch.</div>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary px-4" @disabled($selectable->isEmpty())>
                Schedule selected challenges
            </button>
        </div>
    </div>
</form>

{{-- Readiness overview: every active challenge, and why it can or cannot be scheduled --}}
@if($challenges->isNotEmpty())
<div class="card shadow-sm">
    <div class="card-body">
        <p class="small text-muted mb-2 fw-semibold">Active challenge readiness</p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Image</th>
                        <th>Reveal</th>
                        <th>Ball position</th>
                        <th>Used as daily</th>
                        <th>Selectable</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($challenges as $ch)
                    @php
                        $isUsed       = in_array($ch->id, $usedIds, true);
                        $isSelectable = $ch->isDailyEligible() && !$isUsed;
                    @endphp
                    <tr class="{{ $isSelectable ? '' : 'table-warning' }}">
                        <td>
                            {{ $ch->title }}
                            @if($ch->isDemoContent())
                                <span class="badge bg-warning text-dark">Demo</span>
                            @endif
                        </td>
                        <td>{!! $ch->hidden_image_path ? '<span class="text-success">&check;</span>' : '<span class="text-danger">&cross;</span>' !!}</td>
                        <td>{!! $ch->original_image_path ? '<span class="text-success">&check;</span>' : '<span class="text-muted">&mdash;</span>' !!}</td>
                        <td>{!! $ch->ball_x_ratio !== null && $ch->ball_y_ratio !== null ? '<span class="text-success">&check;</span>' : '<span class="text-danger">&cross;</span>' !!}</td>
                        <td>{!! $isUsed ? '<span class="badge bg-danger">Already used as Daily</span>' : '<span class="text-muted">No</span>' !!}</td>
                        <td>
                            @if($isSelectable)
                                <span class="text-success">Yes</span>
                            @elseif($isUsed)
                                <span class="text-danger">No — already used as Daily</span>
                            @elseif(!$ch->isInDailyPool())
                                <span class="text-muted">No — <span class="badge bg-pool-{{ $ch->usage_pool }}">{{ ucfirst($ch->usage_pool) }}</span> pool</span>
                            @else
                                <span class="text-muted">No — incomplete</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="form-text mt-2">
            A challenge is selectable when it is active, has a hidden image and a ball position, is in the
            <strong>Daily</strong> or <strong>General</strong> pool, and has never been used as a daily challenge.
            Once scheduled it is permanently Daily-used and will never appear in tournaments.
        </div>
    </div>
</div>
@endif

<script>
(function () {
    var list = document.getElementById('challenge-checklist');
    if (!list) return;

    var orderField   = document.getElementById('selection_order');
    var countDisplay = document.getElementById('selected-count');
    var boxes        = Array.prototype.slice.call(list.querySelectorAll('.challenge-checkbox'));
    var order        = [];

    function badgeFor(box) {
        return box.closest('.form-check').querySelector('.order-badge');
    }

    function render() {
        boxes.forEach(function (box) {
            var badge = badgeFor(box);
            var pos   = order.indexOf(box.value);
            if (pos === -1) {
                badge.classList.add('d-none');
                badge.textContent = '';
            } else {
                badge.classList.remove('d-none');
                badge.textContent = pos + 1;
            }
        });
        orderField.value = order.join(',');
        countDisplay.textContent = order.length;
    }

    function track(box) {
        var pos = order.indexOf(box.value);
        if (box.checked && pos === -1) {
            order.push(box.value);
        } else if (!box.checked && pos !== -1) {
            order.splice(pos, 1);
        }
    }

    boxes.forEach(function (box) {
        if (box.checked) order.push(box.value);
        box.addEventListener('change', function () {
            track(box);
            render();
        });
    });

    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('click', function () {
            boxes.forEach(function (box) {
                box.checked = true;
                track(box);
            });
            render();
        });
    }

    var selectNone = document.getElementById('select-none');
    if (selectNone) {
        selectNone.addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = false; });
            order = [];
            render();
        });
    }

    render();
})();
</script>
@endsection
