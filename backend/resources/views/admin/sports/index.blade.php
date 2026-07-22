@extends('admin.layout')

@section('title', 'Sports')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Sports</h1>
</div>

<p class="text-muted small mb-3">
    <strong>Active</strong> = playable and selectable ·
    <strong>Coming soon</strong> = shown in the app but disabled ·
    <strong>Hidden</strong> = not shown to players.
</p>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">Order</th>
                    <th style="width:60px">Colour</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Object</th>
                    <th>Status</th>
                    <th>Set status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sports as $sport)
                <tr>
                    <td class="text-muted small">{{ $sport->sort_order }}</td>
                    <td>
                        <span class="d-inline-block rounded" title="{{ $sport->primary_color }}"
                              style="width:22px; height:22px; border:1px solid #dee2e6; background: {{ $sport->primary_color }};"></span>
                    </td>
                    <td class="fw-semibold">{{ $sport->emoji }} {{ $sport->name }}</td>
                    <td class="small text-muted font-monospace">{{ $sport->slug }}</td>
                    <td class="small text-muted">{{ $sport->object_name ?? '—' }}</td>
                    <td>
                        @if($sport->status === \App\Models\Sport::STATUS_ACTIVE)
                            <span class="badge badge-active">Active</span>
                        @elseif($sport->status === \App\Models\Sport::STATUS_COMING_SOON)
                            <span class="badge bg-warning text-dark">Coming soon</span>
                        @else
                            <span class="badge badge-draft">Hidden</span>
                        @endif
                    </td>
                    <td>
                        @if($sport->slug === 'football')
                            <span class="text-muted small">🔒 Always active</span>
                        @else
                            <form action="/admin/sports/{{ $sport->id }}/status" method="POST" class="d-inline-flex gap-1">
                                @csrf
                                <select name="status" class="form-select form-select-sm" style="width:auto">
                                    <option value="active" @selected($sport->status === 'active')>Active</option>
                                    <option value="coming_soon" @selected($sport->status === 'coming_soon')>Coming soon</option>
                                    <option value="hidden" @selected($sport->status === 'hidden')>Hidden</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No sports yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
