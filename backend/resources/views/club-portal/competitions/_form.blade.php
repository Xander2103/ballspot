{{--
    Portal competition editor. Expects $club, $competition (unsaved on create), $packs, $linked, $inProgress,
    $editable (field => bool), $maxCap, $maxDays. Non-editable fields are disabled (not submitted); the service
    refuses a locked field that changes anyway (competition_rules_locked).
--}}
@php
    $tz = $competition->timezone ?? $club->timezone;
    $fmt = fn ($dt) => $dt ? $dt->copy()->setTimezone($tz)->format('Y-m-d\TH:i') : '';
    $mode = old('replay_mode', $competition->replay_mode?->value ?? 'single_attempt');
    $rule = old('score_rule', $competition->replay_mode?->value === 'multiple_attempts' ? $competition->score_rule?->value : 'best');
    $max = (int) old('max_attempts', $competition->max_attempts > 1 ? $competition->max_attempts : 3);
    $policy = old('normal_play_policy', $competition->normal_play_policy?->value ?? 'blocked');
    $selected = array_map('intval', (array) old('pack_ids', $linked->keys()->all()));
    $dis = fn (string $field) => !$editable[$field] ? 'disabled' : '';
    $lock = fn (string $field) => !$editable[$field] ? ' 🔒' : '';
    $orderedPacks = $packs->sortBy(fn ($p) => [in_array($p->id, $selected, true) ? array_search($p->id, $selected, true) : 1000, $p->name])->values();
@endphp

@if($competition->exists)<input type="hidden" name="version" value="{{ $competition->version }}">@endif

<div class="row g-3 mb-4">
    <div class="col-md-7">
        <label for="name" class="form-label fw-semibold">{{ __('club_portal.competitions.name') }}{{ $lock('name') }}</label>
        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $competition->name) }}" required maxlength="120" placeholder="{{ __('club_portal.competitions.name_placeholder') }}" {{ $dis('name') }}>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label for="description" class="form-label fw-semibold">{{ __('club_portal.competitions.description') }}{{ $lock('description') }}</label>
        <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control @error('description') is-invalid @enderror" {{ $dis('description') }}>{{ old('description', $competition->description) }}</textarea>
        <div class="form-text">{{ __('club_portal.competitions.description_hint') }}</div>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">{{ __('club_portal.competitions.window') }} <span class="text-lowercase fw-normal">({{ __('club_portal.competitions.club_time', ['tz' => $tz]) }})</span></h2>
<div class="row g-3 mb-4" data-section="window">
    <div class="col-md-4">
        <label for="starts_at" class="form-label fw-semibold">{{ __('club_portal.competitions.starts') }}{{ $lock('starts_at') }}</label>
        <input type="datetime-local" id="starts_at" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at', $fmt($competition->starts_at)) }}" {{ $dis('starts_at') }} required>
        @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label for="ends_at" class="form-label fw-semibold">{{ __('club_portal.competitions.ends') }}{{ $lock('ends_at') }}</label>
        <input type="datetime-local" id="ends_at" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at', $fmt($competition->ends_at)) }}" {{ $dis('ends_at') }} required>
        @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 small text-muted d-flex align-items-end">{{ __('club_portal.competitions.window_hint', ['days' => $maxDays]) }}</div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">{{ __('club_portal.competitions.packs') }}{{ $lock('pack_ids') }}</h2>
<div class="mb-4" data-section="packs">
    @if($editable['pack_ids'])<input type="hidden" name="pack_ids_present" value="1">@endif
    @forelse($orderedPacks as $pack)
        @php $busy = $inProgress[$pack->id] ?? 0; $snapshot = $linked->get($pack->id)?->challenge_ids; @endphp
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="pack_ids[]" value="{{ $pack->id }}" id="pack-{{ $pack->id }}" @checked(in_array($pack->id, $selected, true)) {{ $dis('pack_ids') }}>
            <label class="form-check-label" for="pack-{{ $pack->id }}">
                {{ $pack->name }}
                <span class="text-muted small">({{ $snapshot !== null ? __('club_portal.competitions.in_snapshot', ['count' => count($snapshot)]) : __('club_portal.competitions.ready', ['count' => $pack->readyChallenges()->count()]) }})</span>
            </label>
            @if($busy > 0)<div class="small text-warning-emphasis">⚠ {{ __('club_portal.competitions.in_progress', ['count' => $busy]) }}</div>@endif
        </div>
    @empty
        <div class="text-muted small">{{ __('club_portal.competitions.no_packs') }}</div>
    @endforelse
    <div class="form-text">{{ __('club_portal.competitions.packs_hint') }}</div>
    @error('pack_ids')<div class="text-danger small">{{ $message }}</div>@enderror
    @error('pack_ids.*')<div class="text-danger small">{{ $message }}</div>@enderror
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">{{ __('club_portal.competitions.replay_rules') }}{{ $lock('replay_mode') }}</h2>
<div class="mb-3" data-section="replay-rules">
    @foreach(['no_replay' => 'mode_no_replay', 'single_attempt' => 'mode_single', 'multiple_attempts' => 'mode_multiple'] as $value => $key)
        <div class="form-check">
            <input class="form-check-input" type="radio" name="replay_mode" id="mode-{{ $value }}" value="{{ $value }}" @checked($mode === $value) {{ $dis('replay_mode') }}>
            <label class="form-check-label" for="mode-{{ $value }}">{{ __('club_portal.competitions.' . $key) }}</label>
        </div>
    @endforeach
    <div class="row g-2 align-items-center ms-4 mt-1">
        <div class="col-auto"><label for="max_attempts" class="col-form-label small">{{ __('club_portal.competitions.max') }}</label></div>
        <div class="col-auto">
            <select id="max_attempts" name="max_attempts" class="form-select form-select-sm @error('max_attempts') is-invalid @enderror" {{ $dis('max_attempts') }}>
                @for($i = 2; $i <= $maxCap; $i++)<option value="{{ $i }}" @selected($max === $i)>{{ $i }}</option>@endfor
            </select>
        </div>
        <div class="col-auto small">{{ __('club_portal.competitions.score_counts') }}</div>
        <div class="col-auto">
            @foreach(['best' => 'rule_best', 'first' => 'rule_first'] as $value => $key)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="score_rule" id="rule-{{ $value }}" value="{{ $value }}" @checked($rule === $value) {{ $dis('score_rule') }}>
                    <label class="form-check-label small" for="rule-{{ $value }}">{{ __('club_portal.competitions.' . $key) }}</label>
                </div>
            @endforeach
        </div>
        @error('max_attempts')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="form-text">{{ __('club_portal.competitions.rules_hint') }}</div>
</div>

<h2 class="h6 text-uppercase text-muted small mb-3">{{ __('club_portal.competitions.normal_play') }}{{ $lock('normal_play_policy') }}</h2>
<div class="mb-3" data-section="normal-play">
    @foreach(['blocked' => 'policy_blocked', 'allowed' => 'policy_allowed'] as $value => $key)
        <div class="form-check">
            <input class="form-check-input" type="radio" name="normal_play_policy" id="policy-{{ $value }}" value="{{ $value }}" @checked($policy === $value) {{ $dis('normal_play_policy') }}>
            <label class="form-check-label" for="policy-{{ $value }}">{{ __('club_portal.competitions.' . $key) }}</label>
        </div>
    @endforeach
</div>

<div class="alert alert-light border small mb-3">{{ __('club_portal.competitions.tie_break') }}</div>
