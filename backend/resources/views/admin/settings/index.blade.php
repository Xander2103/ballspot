@extends('admin.layout')

@section('title', 'Settings')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Gameplay settings</h1>
</div>

<div class="card shadow-sm" style="max-width: 640px;">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="tournament_challenge_cooldown_days" class="form-label fw-semibold">Tournament challenge cooldown</label>
                <div class="input-group" style="max-width: 220px;">
                    <input type="number" class="form-control @error('tournament_challenge_cooldown_days') is-invalid @enderror"
                           id="tournament_challenge_cooldown_days" name="tournament_challenge_cooldown_days"
                           min="{{ $cooldownMin }}" max="{{ $cooldownMax }}" step="1"
                           value="{{ old('tournament_challenge_cooldown_days', $cooldownDays) }}" required>
                    <span class="input-group-text">days</span>
                </div>
                @error('tournament_challenge_cooldown_days')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                <div class="form-text">
                    BallPicker will prefer tournament photos that players have not seen within this many days.
                    If not enough fresh photos exist, older eligible tournament photos may still be reused.
                    Daily-used photos are never used.
                </div>
                <div class="form-text">
                    Whole number between {{ $cooldownMin }} and {{ $cooldownMax }}. <strong>0</strong> = cooldown disabled.
                    Default: {{ $cooldownDefault }}. Applies to tournament selection only.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>
@endsection
