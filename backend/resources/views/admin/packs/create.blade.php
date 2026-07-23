@extends('admin.layout')

@section('title', 'New Pack')

@section('content')
<div class="mb-3">
    <a href="/admin/packs" class="text-muted small">&larr; Back to Packs</a>
</div>
<h1 class="h3 mb-4">New Pack</h1>

<div class="card shadow-sm" style="max-width: 760px;">
    <div class="card-body">
        <form action="/admin/packs" method="POST" enctype="multipart/form-data">
            @csrf
            @include('admin.packs._fields', ['pack' => null])
            <div class="mt-4">
                <button type="submit" class="btn btn-primary px-4">Create Pack</button>
                <a href="/admin/packs" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
            <p class="text-muted small mt-3 mb-0">You'll add challenges after creating the pack.</p>
        </form>
    </div>
</div>
@endsection
