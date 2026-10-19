{{--
    Competition editor fields (plan §8.4). Expects $club, $competition (unsaved on create), $packs, $linked, $inProgress,
    $editable (field => bool), $locked, $maxCap, $maxDays. Locked fields are rendered disabled with 🔒 — they are
    not submitted, and the service refuses a locked field that changes anyway (competition_rules_locked).
--}}
@php
    $tz = $competition->timezone ?? $club->timezone;
    $fmt = fn ($dt) => $dt ? $dt->copy()->setTimezone($tz)->format('Y-m-d\TH:i') : '';
    $mode = old('replay_mode', $competition->replay_mode?->value ?? 'single_attempt');
    $rule = old('score_rule', $competition->replay_mode?->value === 'multiple_attempts' ? $competition->score_rule->value : 'best');
    $max = (int) old('max_attempts', $competition->max_attempts > 1 ? $competition->max_attempts : 3);
    $policy = old('normal_play_policy', $competition->normal_play_policy?->value ?? 'blocked');
    $selected = array_map('intval', (array) old('pack_ids', $linked->keys()->all()));
    $lock = fn (string $field) => !$editable[$field] ? ' 🔒' : '';
    $dis = fn (string $field) => !$editable[$field] ? 'disabled' : '';
    $orderedPacks = $packs->sortBy(fn ($p) => [in_array($p->id, $selected, true) ? array_search($p->id, $selected, true) : 1000, $p->name])->values();
@endphp

@if($competition->exists)<input type="hidden" name="version" value="{{ $competition->version }}">@endif

<h2 class="h6 text-uppercase text-muted small mb-3">Competition</h2>
<div class="row g-3 mb-4">
    <div class="col-md-7">
        <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $competition->name) }}" required maxlength="120" placeholder="e.g. Autumn Cup">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label for="description" class="form-label fw-semibold">Description</label>
        <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control @error('description') is-invalid @enderror">{{ old('description', $competition->description) }}</textarea>
        <div class="form-text">Shown to members in the app.</div>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">Window <span class="text-muted text-lowercase fw-normal">(club time: {{ $tz }})</span></h2>
<div class="row g-3 mb-4" data-section="window">
    <div class="col-md-4">
        <label for="starts_at" class="form-label fw-semibold">Starts{{ $lock('starts_at') }}</label>
        <input type="datetime-local" id="starts_at" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at', $fmt($competition->starts_at)) }}" {{ $dis('starts_at') }} required>
        @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="ends_at" class="form-label fw-semibold">Ends{{ $locked ? ' 🔒' : '' }}</label>
        <input type="datetime-local" id="ends_at" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at', $fmt($competition->ends_at)) }}" {{ $locked ? 'disabled' : '' }} required>
        @if($locked && $editable['ends_at'])<div class="form-text">After the start the end can only move later — use <strong>Extend end</strong>.</div>@endif
        @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 small text-muted d-flex align-items-end">Scores count only inside [start, end). At most {{ $maxDays }} days.</div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">Packs{{ $lock('pack_ids') }}</h2>
<div class="mb-4" data-section="packs">
    @if($editable['pack_ids'])<input type="hidden" name="pack_ids_present" value="1">@endif
    @forelse($orderedPacks as $pack)
        @php $ready = $pack->readyChallenges()->count(); $busy = $inProgress[$pack->id] ?? 0; @endphp
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="pack_ids[]" value="{{ $pack->id }}" id="pack-{{ $pack->id }}" @checked(in_array($pack->id, $selected, true)) {{ $dis('pack_ids') }}>
            <label class="form-check-label" for="pack-{{ $pack->id }}">
                {{ $pack->name }} <span class="text-muted small">({{ $linked->get($pack->id)?->challenge_ids !== null ? count($linked->get($pack->id)->challenge_ids) . ' in snapshot' : $ready . ' ready' }})</span>
                @if($pack->status !== 'active')<span class="badge badge-{{ $pack->status }}">{{ ucfirst($pack->status) }}</span>@endif
            </label>
            @if($busy > 0)<div class="small text-warning-emphasis" data-warning="in-progress">⚠ "{{ $pack->name }}" has {{ $busy }} normal {{ $busy === 1 ? 'attempt' : 'attempts' }} in progress (they may still finish).</div>@endif
        </div>
    @empty
        <div class="text-muted small">This club has no packs yet. <a href="{{ route('admin.clubs.packs.create', $club) }}">Create a club pack</a> first.</div>
    @endforelse
    <div class="form-text">Only this club's packs. They need to be active with at least one ready challenge to publish. The challenge list is snapshotted when the rules lock.</div>
    @error('pack_ids')<div class="text-danger small">{{ $message }}</div>@enderror
    @error('pack_ids.*')<div class="text-danger small">{{ $message }}</div>@enderror
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">Replay rules{{ $lock('replay_mode') }}</h2>
<div class="mb-3" data-section="replay-rules">
    <div class="form-check">
        <input class="form-check-input" type="radio" name="replay_mode" id="mode-no-replay" value="no_replay" @checked($mode === 'no_replay') {{ $dis('replay_mode') }}>
        <label class="form-check-label" for="mode-no-replay">No replay — players who already completed a pack cannot play it</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="radio" name="replay_mode" id="mode-single" value="single_attempt" @checked($mode === 'single_attempt') {{ $dis('replay_mode') }}>
        <label class="form-check-label" for="mode-single">One competition attempt per pack <span class="text-muted">(recommended)</span></label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="radio" name="replay_mode" id="mode-multiple" value="multiple_attempts" @checked($mode === 'multiple_attempts') {{ $dis('replay_mode') }}>
        <label class="form-check-label" for="mode-multiple">Multiple attempts</label>
    </div>
    <div class="row g-2 align-items-center ms-4 mt-1">
        <div class="col-auto"><label for="max_attempts" class="col-form-label small">Max</label></div>
        <div class="col-auto">
            <select id="max_attempts" name="max_attempts" class="form-select form-select-sm @error('max_attempts') is-invalid @enderror" {{ $dis('max_attempts') }}>
                @for($i = 2; $i <= $maxCap; $i++)<option value="{{ $i }}" @selected($max === $i)>{{ $i }}</option>@endfor
            </select>
        </div>
        <div class="col-auto small">Score counts:</div>
        <div class="col-auto">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="score_rule" id="rule-best" value="best" @checked($rule === 'best') {{ $dis('score_rule') }}>
                <label class="form-check-label small" for="rule-best">Best</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="score_rule" id="rule-first" value="first" @checked($rule === 'first') {{ $dis('score_rule') }}>
                <label class="form-check-label small" for="rule-first">First</label>
            </div>
        </div>
        @error('max_attempts')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="form-text">Max and score rule apply to multiple attempts only; the other modes always count the single attempt.</div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">Normal play of the linked packs{{ $lock('normal_play_policy') }}</h2>
<div class="mb-3" data-section="normal-play">
    <div class="form-check">
        <input class="form-check-input" type="radio" name="normal_play_policy" id="policy-blocked" value="blocked" @checked($policy === 'blocked') {{ $dis('normal_play_policy') }}>
        <label class="form-check-label" for="policy-blocked">Blocked from publish until finalize <span class="text-muted">(default — nobody previews the photos)</span></label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="radio" name="normal_play_policy" id="policy-allowed" value="allowed" @checked($policy === 'allowed') {{ $dis('normal_play_policy') }}>
        <label class="form-check-label" for="policy-allowed">Allowed — the packs stay playable outside the competition</label>
    </div>
</div>

<div class="alert alert-light border small mb-3" data-section="tie-break">
    <strong>Tie-break:</strong> total score → reached first → fewer attempts (shared rank if equal).<br>
    🔒 Rules lock automatically at the start time.
</div>
