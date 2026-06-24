@extends('admin.layout')

@section('title', 'New Daily Challenge')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.daily.index') }}" class="text-muted small">&larr; Back to Daily Challenges</a>
</div>
<h1 class="h3 mb-4">New Daily Challenge</h1>

<div class="card shadow-sm" style="max-width: 580px;">
    <div class="card-body">
        <form action="{{ route('admin.daily.store') }}" method="POST">
            @csrf

            <div class="mb-3">
                <label for="challenge_id" class="form-label fw-semibold">Challenge <span class="text-danger">*</span></label>
                <select id="challenge_id" name="challenge_id" class="form-select @error('challenge_id') is-invalid @enderror" required>
                    <option value="">— Select an active challenge —</option>
                    @foreach($challenges as $challenge)
                    <option value="{{ $challenge->id }}" {{ old('challenge_id') == $challenge->id ? 'selected' : '' }}>
                        {{ $challenge->title }}
                        {{ $challenge->isReady() ? '✓' : '⚠ incomplete' }}
                        @if(!$challenge->original_image_path) · no reveal @endif
                        @if($challenge->isDemoContent()) · Demo @endif
                    </option>
                    @endforeach
                </select>
                <div class="form-text">Only active challenges are listed. ✓ = ready for daily. ⚠ = missing image or ball position.</div>
                @error('challenge_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Readiness summary table --}}
            @if($challenges->isNotEmpty())
            <div class="mb-3">
                <p class="small text-muted mb-1 fw-semibold">Active challenge readiness:</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Image</th>
                                <th>Reveal</th>
                                <th>Ball pos</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($challenges as $ch)
                        <tr class="{{ $ch->isReady() ? '' : 'table-warning' }}">
                            <td>
                                {{ $ch->title }}
                                @if($ch->isDemoContent())
                                    <span class="badge bg-warning text-dark">Demo</span>
                                @endif
                            </td>
                            <td>{!! $ch->hidden_image_path ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>' !!}</td>
                            <td>{!! $ch->original_image_path ? '<span class="text-success">✓</span>' : '<span class="text-muted">—</span>' !!}</td>
                            <td>{!! $ch->ball_x_ratio !== null ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>' !!}</td>
                        </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <div class="mb-3">
                <label for="challenge_date" class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                <input type="date" id="challenge_date" name="challenge_date"
                       class="form-control @error('challenge_date') is-invalid @enderror"
                       value="{{ old('challenge_date', today()->toDateString()) }}" required>
                @error('challenge_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label for="status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    @foreach(['scheduled','active','archived'] as $s)
                    <option value="{{ $s }}" {{ old('status', 'active') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-primary px-4">Create Daily Challenge</button>
        </form>
    </div>
</div>
@endsection
