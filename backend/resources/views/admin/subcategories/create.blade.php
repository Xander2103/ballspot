@extends('admin.layout')

@section('title', 'New Subcategory')

@section('content')
<div class="mb-3">
    <a href="/admin/subcategories" class="text-muted small">&larr; Back to Subcategories</a>
</div>
<h1 class="h3 mb-4">New Subcategory</h1>

<div class="card shadow-sm" style="max-width: 720px;">
    <div class="card-body">
        <form action="/admin/subcategories" method="POST">
            @include('admin.subcategories._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-primary px-4">Create Subcategory</button>
                <a href="/admin/subcategories" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
