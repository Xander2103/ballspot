@extends('admin.layout')

@section('title', 'Notices')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">In-app notice</h1>
    @if($isLive)
        <span class="badge bg-success">Live in the app now</span>
    @else
        <span class="badge bg-secondary">Not shown</span>
    @endif
</div>

<p class="text-muted" style="max-width: 720px;">
    A short temporary message on the Home screen, right above the Daily Challenge card —
    for example “Daily login starts tomorrow”. Players see it in their own language; a
    language you leave empty falls back to English, then to any language you did fill in.
</p>

<div class="card shadow-sm" style="max-width: 720px;">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.notices.update') }}">
            @csrf
            @method('PUT')

            <div class="form-check form-switch mb-3">
                <input type="hidden" name="enabled" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1"
                       {{ old('enabled', $notice->enabled) ? 'checked' : '' }}>
                <label class="form-check-label fw-semibold" for="enabled">Enabled</label>
                <div class="form-text">Off = nothing is shown, whatever the dates say.</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="placement" class="form-label fw-semibold">Placement</label>
                    <select class="form-select" id="placement" name="placement">
                        @foreach($placements as $placement)
                            <option value="{{ $placement }}" {{ old('placement', $notice->placement) === $placement ? 'selected' : '' }}>
                                {{ $placement === 'home_daily_card' ? 'Home — above the Daily Challenge card' : $placement }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="type" class="form-label fw-semibold">Type</label>
                    <select class="form-select @error('type') is-invalid @enderror" id="type" name="type">
                        @foreach($types as $type)
                            <option value="{{ $type }}" {{ old('type', $notice->type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    @error('type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <div class="form-text">Info = neutral, Warning = amber, Success = green.</div>
                </div>
            </div>

            @foreach($languages as $lang)
                @php($field = 'message_' . $lang)
                <div class="mb-3">
                    <label for="{{ $field }}" class="form-label fw-semibold">Message ({{ strtoupper($lang) }})</label>
                    <input type="text" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}"
                           maxlength="{{ $messageMax }}" value="{{ old($field, $notice->{$field}) }}"
                           placeholder="{{ $lang === 'en' ? 'Daily login starts tomorrow' : '' }}">
                    @error($field)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            @endforeach

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="starts_at" class="form-label fw-semibold">Show from <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" id="starts_at" name="starts_at"
                           value="{{ old('starts_at', $notice->starts_at?->format('Y-m-d\TH:i')) }}">
                    @error('starts_at')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="ends_at" class="form-label fw-semibold">Hide after <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="datetime-local" class="form-control @error('ends_at') is-invalid @enderror" id="ends_at" name="ends_at"
                           value="{{ old('ends_at', $notice->ends_at?->format('Y-m-d\TH:i')) }}">
                    @error('ends_at')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <div class="form-text">Server time ({{ config('app.timezone') }}). Leave both empty to show it until you switch it off.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save notice</button>
        </form>
    </div>
</div>
@endsection
