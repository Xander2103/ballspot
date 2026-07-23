@extends('admin.layout')

@section('title', 'Subcategories')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Subcategories</h1>
    <a href="/admin/subcategories/create" class="btn btn-primary btn-sm">+ New Subcategory</a>
</div>

<p class="text-muted small mb-3">
    Curated groups for organising and filtering content (team, country, league, difficulty…).
    Separate from free-text challenge tags. Deactivating hides a subcategory from app filters but
    keeps its history and challenge links.
</p>

<form method="GET" class="row g-2 mb-3">
    <div class="col-auto">
        <select name="sport_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All sports</option>
            @foreach($sports as $s)
                <option value="{{ $s->id }}" {{ (string) $filterSport === (string) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All types</option>
            @foreach($types as $t)
                <option value="{{ $t }}" {{ $filterType === $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    @if($filterSport || $filterType)
        <div class="col-auto"><a href="/admin/subcategories" class="btn btn-sm btn-outline-secondary">Clear</a></div>
    @endif
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">Order</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Sport</th>
                    <th>Slug</th>
                    <th>Challenges</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($subcategories as $sub)
                <tr>
                    <td class="text-muted small">{{ $sub->sort_order }}</td>
                    <td class="fw-semibold">
                        @if($sub->color)<span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:{{ $sub->color }}"></span>@endif
                        {{ $sub->icon ? $sub->icon.' ' : '' }}{{ $sub->name }}
                    </td>
                    <td><span class="badge bg-secondary">{{ $sub->type }}</span></td>
                    <td class="small text-muted">{{ $sub->sport->name ?? 'Global' }}</td>
                    <td class="small text-muted font-monospace">{{ $sub->slug }}</td>
                    <td class="small">{{ $sub->challenges_count }}</td>
                    <td>
                        @if($sub->is_active)
                            <span class="badge badge-active">Active</span>
                        @else
                            <span class="badge badge-draft">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <a href="/admin/subcategories/{{ $sub->id }}/edit" class="btn btn-outline-secondary btn-sm me-1">Edit</a>
                        <form action="/admin/subcategories/{{ $sub->id }}/status" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $sub->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                {{ $sub->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No subcategories yet. <a href="/admin/subcategories/create">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
