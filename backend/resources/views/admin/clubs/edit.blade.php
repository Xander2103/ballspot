@extends('admin.layout')

@section('title', 'Edit ' . $club->name)

@section('content')
<div class="mb-3"><a href="{{ route('admin.clubs.show', $club) }}" class="text-muted small">&larr; Back to {{ $club->name }}</a></div>
<div class="d-flex align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">Edit club details</h1>
    @include('admin.clubs._status', ['club' => $club])
</div>
<p class="text-muted small mb-4">Identity, location and contact. Access and gameplay defaults live on the <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'settings']) }}">Settings</a> tab.</p>

<form action="{{ route('admin.clubs.update', $club) }}" method="POST" enctype="multipart/form-data" class="card shadow-sm" style="max-width: 900px;">
    @csrf
    @method('PUT')
    <input type="hidden" name="version" value="{{ old('version', $club->version) }}">
    <div class="card-body">
        @include('admin.clubs._form', ['club' => $club])
    </div>
    <div class="card-footer bg-white d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary px-4">Save details</button>
        <a href="{{ route('admin.clubs.show', $club) }}" class="btn btn-outline-secondary">Cancel</a>
        <span class="text-muted small ms-auto">Version {{ $club->version }} · if someone else saves first, you are asked to reload.</span>
    </div>
</form>
@endsection
