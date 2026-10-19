@extends('admin.layout')

@section('title', 'New competition · ' . $club->name)

@section('content')
<div class="mb-2"><a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'competitions']) }}" class="text-muted small">&larr; Back to {{ $club->name }}</a></div>
<h1 class="h4 mb-3">New competition <span class="text-muted fw-normal">· {{ $club->name }}</span></h1>

<form method="POST" action="{{ route('admin.clubs.competitions.store', $club) }}" class="card shadow-sm" style="max-width: 860px;" data-section="competition-editor">
    @csrf
    <div class="card-body">
        @include('admin.clubs.competitions._form')
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save draft</button>
            <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'competitions']) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
        <div class="form-text mt-2">Drafts are invisible to members. Publish from the competition page once it is ready.</div>
    </div>
</form>
@endsection
