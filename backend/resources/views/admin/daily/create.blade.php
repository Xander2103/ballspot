@extends('admin.layout')

@section('title', 'New Daily Challenge')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.daily.index') }}" class="text-muted small">&larr; Back to Daily Challenges</a>
</div>
<h1 class="h3 mb-4">New Daily Challenge</h1>

<div class="card shadow-sm" style="max-width: 540px;">
    <div class="card-body">
        <form action="{{ route('admin.daily.store') }}" method="POST">
            @csrf

            <div class="mb-3">
                <label for="challenge_id" class="form-label fw-semibold">Challenge <span class="text-danger">*</span></label>
                <select id="challenge_id" name="challenge_id" class="form-select @error('challenge_id') is-invalid @enderror" required>
                    <option value="">— Select a challenge —</option>
                    @foreach($challenges as $challenge)
                    <option value="{{ $challenge->id }}" {{ old('challenge_id') == $challenge->id ? 'selected' : '' }}>
                        {{ $challenge->title }}
                    </option>
                    @endforeach
                </select>
                @error('challenge_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

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
