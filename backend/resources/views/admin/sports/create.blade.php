@extends('admin.layout')

@section('title', 'New Sport')

@section('content')
<div class="mb-3">
    <a href="/admin/sports" class="text-muted small">&larr; Back to Sports</a>
</div>
<h1 class="h3 mb-4">New Sport</h1>

<div class="card shadow-sm" style="max-width: 760px;">
    <div class="card-body">
        <form action="/admin/sports" method="POST">
            @csrf
            @include('admin.sports._fields', ['sport' => null])
            <div class="mt-4">
                <button type="submit" class="btn btn-primary px-4">Create Sport</button>
                <a href="/admin/sports" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
