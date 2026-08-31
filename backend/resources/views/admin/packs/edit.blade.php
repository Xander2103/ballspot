@extends('admin.layout')

@section('title', 'Edit Pack')

@section('content')
<div class="mb-3">
    <a href="/admin/packs" class="text-muted small">&larr; Back to Packs</a>
</div>
<h1 class="h3 mb-4">Edit Pack — {{ $pack->name }}</h1>

<div class="card shadow-sm mb-4" style="max-width: 900px;">
    <div class="card-body">
        <form action="/admin/packs/{{ $pack->id }}" method="POST" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            @include('admin.packs._fields')

            <hr class="my-4">
            <h6 class="fw-semibold mb-1">Challenges in this pack</h6>
            <p class="text-muted small mb-2">
                Selection order sets pack order. Detaching a challenge never deletes it or its image.
                @if($pack->sport_id)Only {{ $pack->sport->name ?? 'this sport' }}'s challenges are listed.@endif
            </p>

            @if($available->isEmpty())
                <p class="text-muted small">No challenges available{{ $pack->sport_id ? ' for this sport' : '' }} yet.</p>
            @else
                <select name="challenges[]" multiple size="12" class="form-select">
                    @foreach($available as $ch)
                        <option value="{{ $ch->id }}" {{ $selected->contains($ch->id) ? 'selected' : '' }}>
                            {{ $ch->title }} — {{ ucfirst($ch->difficulty) }} / {{ ucfirst($ch->status) }} [{{ ucfirst($ch->usage_pool) }} pool]{{ $ch->isReadyForDaily() ? '' : ' (not ready)' }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">Hold Ctrl/Cmd to select multiple. {{ $selected->count() }} currently in pack.</div>
            @endif

            <div class="mt-4">
                <button type="submit" class="btn btn-primary px-4">Save Pack</button>
                <a href="/admin/packs" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
