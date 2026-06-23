@extends('admin.layout')

@section('title', 'Categories')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenge Categories</h1>
    <a href="/admin/categories/create" class="btn btn-primary btn-sm">+ New Category</a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">Order</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Description</th>
                    <th>Challenges</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $cat)
                <tr>
                    <td class="text-muted small">{{ $cat->sort_order }}</td>
                    <td class="fw-semibold">{{ $cat->name }}</td>
                    <td class="small text-muted font-monospace">{{ $cat->slug }}</td>
                    <td class="small text-muted">{{ $cat->description ?? '—' }}</td>
                    <td class="small">{{ $cat->challenges()->count() }}</td>
                    <td>
                        @if($cat->is_active)
                            <span class="badge badge-active">Active</span>
                        @else
                            <span class="badge badge-draft">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <a href="/admin/categories/{{ $cat->id }}/edit" class="btn btn-outline-secondary btn-sm me-1">Edit</a>
                        <form action="/admin/categories/{{ $cat->id }}/toggle" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $cat->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} me-1">
                                {{ $cat->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        <form action="/admin/categories/{{ $cat->id }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this category? Challenges will be uncategorised.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No categories yet. <a href="/admin/categories/create">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
