@extends('admin.layout')

@section('title', 'Edit Category')

@section('content')
<div class="mb-3">
    <a href="/admin/categories" class="text-muted small">&larr; Back to Categories</a>
</div>
<h1 class="h3 mb-4">Edit Category</h1>

<div class="card shadow-sm" style="max-width: 540px;">
    <div class="card-body">
        <form action="/admin/categories/{{ $category->id }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="mb-3">
                <label for="name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $category->name) }}" required maxlength="100">
                <div class="form-text">Slug: <code>{{ $category->slug }}</code> (auto-updated on save)</div>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label fw-semibold">Description</label>
                <textarea id="description" name="description" class="form-control @error('description') is-invalid @enderror"
                          rows="2" maxlength="500">{{ old('description', $category->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="sort_order" class="form-label fw-semibold">Sort order</label>
                <input type="number" id="sort_order" name="sort_order"
                       class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', $category->sort_order) }}" min="0" style="max-width:120px;">
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">Save Changes</button>
            <a href="/admin/categories" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>
    </div>
</div>
@endsection
