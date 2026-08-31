{{--
    Usage pool selector (v1.8.9 fairness rules).
    Expects: $current (string pool value), optional $challenge (for the daily-used notice).
--}}
@php
    $pools = [
        \App\Models\Challenge::POOL_DAILY      => 'Daily only',
        \App\Models\Challenge::POOL_TOURNAMENT => 'Tournament',
        \App\Models\Challenge::POOL_PACK       => 'Pack',
        \App\Models\Challenge::POOL_GENERAL    => 'General',
    ];
    $dailyUsed = isset($challenge) && $challenge->isDailyUsed();
@endphp
<div class="mb-3">
    <label for="usage_pool" class="form-label fw-semibold">Usage pool <span class="text-danger">*</span></label>
    <select id="usage_pool" name="usage_pool" class="form-select @error('usage_pool') is-invalid @enderror" required>
        @foreach($pools as $value => $label)
        <option value="{{ $value }}" {{ $current === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <div class="form-text">
        <div><span class="badge bg-pool-daily">Daily only</span> Daily challenges are exclusive and will not be used in tournaments. <strong>Once used as a Daily, a photo is excluded from tournaments forever.</strong></div>
        <div class="mt-1"><span class="badge bg-pool-tournament">Tournament</span> Tournament challenges are used for friend tournaments (never reused within one tournament).</div>
        <div class="mt-1"><span class="badge bg-pool-pack">Pack</span> Pack challenges are used for singleplayer challenge packs — kept separate from Daily and Tournament content, never auto-selected.</div>
        <div class="mt-1"><span class="badge bg-pool-general">General</span> may be used for Daily <em>and</em> tournaments (until used as a Daily).</div>
    </div>
    @if($dailyUsed)
    <div class="alert alert-danger py-2 small mt-2 mb-0">
        🔒 <strong>Already used as a Daily Challenge.</strong> This photo is excluded from all new tournaments and cannot
        be scheduled as a daily again, whatever pool is selected.
    </div>
    @endif
    @error('usage_pool')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
