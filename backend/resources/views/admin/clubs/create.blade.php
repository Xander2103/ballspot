@extends('admin.layout')

@section('title', 'Create club')

@section('content')
<div class="mb-3"><a href="{{ route('admin.clubs.index') }}" class="text-muted small">&larr; Back to Clubs</a></div>
<h1 class="h4 mb-1">Create club</h1>
<p class="text-muted small mb-4">Clubs are created by BallPicker admins only. Members join with an invite code you create after saving.</p>

<form action="{{ route('admin.clubs.store') }}" method="POST" enctype="multipart/form-data" class="card shadow-sm" style="max-width: 900px;">
    @csrf
    <div class="card-body">
        @include('admin.clubs._form', ['club' => null])

        <h2 class="h6 text-uppercase text-muted small mb-3">Access</h2>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="owner" class="form-label fw-semibold">First owner <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" id="owner" name="owner" class="form-control @error('owner') is-invalid @enderror" value="{{ old('owner') }}" maxlength="190" placeholder="username or email of an existing player" autocomplete="off">
                <div class="form-text">Without an owner the club is flagged <span class="badge badge-needs-owner">Needs owner</span> until you add one.</div>
                @error('owner')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="max_members" class="form-label fw-semibold">Max members</label>
                <input type="number" id="max_members" name="max_members" class="form-control @error('max_members') is-invalid @enderror" value="{{ old('max_members') }}" min="1" placeholder="{{ config('ballspot.clubs.limits.max_members', 500) }} (default)">
                @error('max_members')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="hidden" name="join_enabled" value="0">
                    <input class="form-check-input" type="checkbox" id="join_enabled" name="join_enabled" value="1" {{ old('join_enabled', '1') ? 'checked' : '' }}>
                    <label class="form-check-label" for="join_enabled">Joining with codes enabled</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Create club</button>
        <a href="{{ route('admin.clubs.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
