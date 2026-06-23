@extends('admin.layout')

@section('title', 'New Category')

@section('content')
<div class="mb-3">
    <a href="/admin/categories" class="text-muted small">&larr; Back to Categories</a>
</div>
<h1 class="h3 mb-4">New Category</h1>

<div class="card shadow-sm" style="max-width: 540px;">
    <div class="card-body">
        <form action="/admin/categories" method="POST">
            @csrf

            <div class="mb-3">
                <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Corner Kicks">
                <div class="form-text">The slug is generated automatically from the name.</div>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label fw-semibold">Description</label>
                <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror"
                          rows="2" maxlength="500" placeholder="Short description (optional)">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="sort_order" class="form-label fw-semibold">Sort order</label>
                <input type="number" id="sort_order" name="sort_order"
                       class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', 0) }}" min="0" style="max-width:120px;">
                <div class="form-text">Lower numbers appear first.</div>
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           {{ old('is_active', '1') ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">Create Category</button>
            <a href="/admin/categories" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
    </div>
</div>
@endsection
