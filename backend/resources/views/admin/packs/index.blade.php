@extends('admin.layout')

@section('title', 'Packs')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenge Packs</h1>
    <a href="/admin/packs/create" class="btn btn-primary btn-sm">+ New Pack</a>
</div>

<p class="text-muted small mb-3">
    Curated content collections (e.g. Belgium Pack, Easy Starter Pack). Content only â€” no prices,
    no purchases. Only <strong>active</strong> + <strong>public</strong> packs appear in the app.
</p>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">Order</th>
                    <th>Name</th>
                    <th>Sport</th>
                    <th>Challenges</th>
                    <th>Status</th>
                    <th>Visibility</th>
                    <th>Featured</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packs as $pack)
                <tr>
                    <td class="text-muted small">{{ $pack->sort_order }}</td>
                    <td>
                        <div class="fw-semibold">{{ $pack->name }}</div>
                        <div class="text-muted small font-monospace">{{ $pack->slug }}</div>
                    </td>
                    <td class="small text-muted">{{ $pack->sport->name ?? 'Global' }}</td>
                    <td class="small">
                        {{ $pack->challenges_count }}
                        @if($pack->status === 'active' && $pack->readyChallenges()->isEmpty())
                            <span class="badge bg-warning text-dark ms-1" title="Active but no ready challenges">âš  no ready</span>
                        @endif
                    </td>
                    <td>
                        @if($pack->status === 'active')
                            <span class="badge badge-active">Active</span>
                        @elseif($pack->status === 'archived')
                            <span class="badge badge-archived">Archived</span>
                        @else
                            <span class="badge badge-draft">Draft</span>
                        @endif
                    </td>
                    <td class="small">{{ ucfirst($pack->visibility) }}</td>
                    <td class="small">{{ $pack->is_featured ? 'â˜…' : 'â€”' }}</td>
                    <td>
                        <a href="/admin/packs/{{ $pack->id }}/edit" class="btn btn-outline-secondary btn-sm">Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No packs yet. <a href="/admin/packs/create">Create one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $packs->links() }}</div>
</div>
@endsection
