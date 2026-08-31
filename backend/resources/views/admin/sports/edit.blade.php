@extends('admin.layout')

@section('title', 'Edit Sport')

@section('content')
<div class="mb-3">
    <a href="/admin/sports" class="text-muted small">&larr; Back to Sports</a>
</div>
<h1 class="h3 mb-4">Edit Sport — {{ $sport->emoji }} {{ $sport->name }}</h1>

<div class="card shadow-sm" style="max-width: 760px;">
    <div class="card-body">
        <form action="/admin/sports/{{ $sport->id }}" method="POST">
            @csrf
            @method('PUT')
            @include('admin.sports._fields', ['sport' => $sport])
            <div class="mt-4">
                <button type="submit" class="btn btn-primary px-4">Save Sport</button>
                <a href="/admin/sports" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
