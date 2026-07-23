@extends('admin.layout')

@section('title', 'Challenges')

@section('content')
@include('admin.partials.backup-reminder')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenges</h1>
    <a href="/admin/challenges/create" class="btn btn-primary btn-sm">+ New Challenge</a>
</div>

{{-- Filters --}}
<form method="GET" action="/admin/challenges" class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <input type="search" name="search" value="{{ request('search') }}"
                   class="form-control form-control-sm" placeholder="Search title…">
        </div>
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(['draft','active','archived'] as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="difficulty" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All difficulties</option>
                @foreach(['easy','medium','hard'] as $d)
                    <option value="{{ $d }}" {{ request('difficulty') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="has_reveal" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Reveal image?</option>
                <option value="yes" {{ request('has_reveal') === 'yes' ? 'selected' : '' }}>Has reveal</option>
                <option value="no"  {{ request('has_reveal') === 'no'  ? 'selected' : '' }}>No reveal</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="used_as_daily" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Used as daily?</option>
                <option value="yes" {{ request('used_as_daily') === 'yes' ? 'selected' : '' }}>Used as daily</option>
                <option value="no"  {{ request('used_as_daily') === 'no'  ? 'selected' : '' }}>Never used</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        </div>
        @if(request()->hasAny(['search','status','difficulty','category','has_reveal','used_as_daily']))
        <div class="col-auto">
            <a href="/admin/challenges" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
        @endif
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:72px">Hidden</th>
                    <th style="width:72px">Reveal</th>
                    <th>Title</th>
                    <th>Readiness</th>
                    <th>Difficulty</th>
                    <th>Status</th>
                    <th>Ball</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($challenges as $challenge)
                <tr>
                    <td>
                        @if($challenge->hidden_image_path)
                        <img src="{{ asset('storage/' . $challenge->hidden_image_path) }}"
                             alt="hidden" style="width:60px;height:44px;object-fit:cover;border-radius:4px;">
                        @else
                        <span class="text-danger small">Missing</span>
                        @endif
                    </td>
                    <td>
                        @if($challenge->original_image_path)
                        <img src="{{ asset('storage/' . $challenge->original_image_path) }}"
                             alt="reveal" style="width:60px;height:44px;object-fit:cover;border-radius:4px;">
                        @else
                        <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $challenge->title }}</div>
                        <div class="text-muted small">
                            {{ $challenge->category?->name ?? '—' }}
                            @if($challenge->isDemoContent())
                                <span class="badge bg-warning text-dark ms-1" title="This is placeholder demo content">Demo</span>
                            @endif
                            @if($challenge->used_as_daily)
                                <span class="badge bg-info text-dark ms-1">Used as daily</span>
                            @endif
                        </div>
                        @if($challenge->subcategories->isNotEmpty())
                            <div class="mt-1">
                                @foreach($challenge->subcategories as $sub)
                                    <span class="badge rounded-pill bg-light text-dark border" style="{{ $sub->color ? 'border-color:'.$sub->color.' !important;' : '' }}">{{ $sub->icon ? $sub->icon.' ' : '' }}{{ $sub->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td>
                        @if($challenge->isReady())
                            <span class="badge bg-success">Ready</span>
                        @else
                            @php
                                $missing = [];
                                if(empty($challenge->hidden_image_path)) $missing[] = 'image';
                                if($challenge->ball_x_ratio === null) $missing[] = 'ball pos';
                                if(empty($challenge->title)) $missing[] = 'title';
                            @endphp
                            <span class="badge bg-danger" title="Missing: {{ implode(', ', $missing) }}">
                                Incomplete
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-{{ $challenge->difficulty }}">{{ ucfirst($challenge->difficulty) }}</span>
                    </td>
                    <td>
                        <span class="badge badge-{{ $challenge->status }}">{{ ucfirst($challenge->status) }}</span>
                    </td>
                    <td class="small text-muted">
                        @if($challenge->ball_x_ratio !== null)
                            {{ round($challenge->ball_x_ratio * 100) }}%,
                            {{ round($challenge->ball_y_ratio * 100) }}%
                        @else
                            <span class="text-danger">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <a href="/admin/challenges/{{ $challenge->id }}/edit"
                               class="btn btn-outline-secondary btn-sm">Edit</a>
                            <a href="/admin/challenges/{{ $challenge->id }}/preview"
                               class="btn btn-outline-info btn-sm">Preview</a>

                            {{-- Quick status actions --}}
                            @if($challenge->status !== 'archived')
                            <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="status" value="archived">
                                <button type="submit" class="btn btn-outline-warning btn-sm"
                                        onclick="return confirm('Archive this challenge?')">Archive</button>
                            </form>
                            @endif

                            @if($challenge->status === 'archived' || $challenge->status === 'active')
                            <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="status" value="draft">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">→ Draft</button>
                            </form>
                            @endif

                            @if($challenge->status !== 'active' && $challenge->isReady())
                            <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="status" value="active">
                                <button type="submit" class="btn btn-outline-success btn-sm">Activate</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No challenges found. <a href="/admin/challenges/create">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($challenges->hasPages())
<div class="mt-3">
    {{ $challenges->links() }}
</div>
@endif
@endsection
