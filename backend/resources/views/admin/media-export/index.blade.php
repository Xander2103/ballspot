@extends('admin.layout')
@section('title', 'Media Export')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-1">Media Export</h1>
        <p class="text-secondary small mb-0">Filter challenge images and download them as one ZIP (guess + reveal images, <code>manifest.json</code>, <code>manifest.csv</code>). Read-only: nothing is changed.</p>
    </div>
</div>

@if ($errors->has('export'))
    <div class="alert alert-warning">{{ $errors->first('export') }}</div>
@endif

<form method="GET" action="{{ route('admin.media-export.index') }}" class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Sport</label>
                <select name="sport" class="form-select form-select-sm">
                    <option value="">All sports</option>
                    @foreach($sports as $sport)
                        <option value="{{ $sport->id }}" {{ (string) $filters['sport'] === (string) $sport->id ? 'selected' : '' }}>{{ $sport->emoji }} {{ $sport->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Usage pool</label>
                <select name="usage_pool" class="form-select form-select-sm">
                    <option value="">All pools</option>
                    @foreach(\App\Models\Challenge::POOLS as $pool)
                        <option value="{{ $pool }}" {{ $filters['usage_pool'] === $pool ? 'selected' : '' }}>{{ ucfirst($pool) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach(['draft', 'active', 'archived'] as $s)
                        <option value="{{ $s }}" {{ $filters['status'] === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Difficulty</label>
                <select name="difficulty" class="form-select form-select-sm">
                    <option value="">All difficulties</option>
                    @foreach(['easy', 'medium', 'hard'] as $d)
                        <option value="{{ $d }}" {{ $filters['difficulty'] === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Daily usage</label>
                <select name="used_as_daily" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="yes" {{ $filters['used_as_daily'] === 'yes' ? 'selected' : '' }}>Used as Daily</option>
                    <option value="no"  {{ $filters['used_as_daily'] === 'no'  ? 'selected' : '' }}>Not used as Daily</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Pack usage</label>
                <select name="pack" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="any"  {{ $filters['pack'] === 'any'  ? 'selected' : '' }}>In any pack</option>
                    <option value="none" {{ $filters['pack'] === 'none' ? 'selected' : '' }}>Not in any pack</option>
                    @foreach($packs as $pack)
                        <option value="{{ $pack->id }}" {{ (string) $filters['pack'] === (string) $pack->id ? 'selected' : '' }}>Pack: {{ $pack->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Tournament usage</label>
                <select name="tournament" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="used"     {{ $filters['tournament'] === 'used'     ? 'selected' : '' }}>Used in tournament rounds</option>
                    <option value="not_used" {{ $filters['tournament'] === 'not_used' ? 'selected' : '' }}>Not used in tournament rounds</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Image type</label>
                <select name="image_type" class="form-select form-select-sm">
                    <option value="both"   {{ $filters['image_type'] === 'both'   ? 'selected' : '' }}>Both (guess + reveal)</option>
                    <option value="guess"  {{ $filters['image_type'] === 'guess'  ? 'selected' : '' }}>Guess image only (without ball)</option>
                    <option value="reveal" {{ $filters['image_type'] === 'reveal' ? 'selected' : '' }}>Reveal image only (with ball)</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Created from / to</label>
                <div class="d-flex gap-1">
                    <input type="date" name="created_from" value="{{ $filters['created_from'] }}" class="form-control form-control-sm">
                    <input type="date" name="created_to"   value="{{ $filters['created_to'] }}"   class="form-control form-control-sm">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-secondary mb-1">Updated from / to</label>
                <div class="d-flex gap-1">
                    <input type="date" name="updated_from" value="{{ $filters['updated_from'] }}" class="form-control form-control-sm">
                    <input type="date" name="updated_to"   value="{{ $filters['updated_to'] }}"   class="form-control form-control-sm">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-secondary mb-1">Title contains</label>
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm" placeholder="Search title…">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
                <a href="{{ route('admin.media-export.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="text-secondary small">Matching challenges</div><div class="fs-4 fw-semibold">{{ $summary['challenges'] }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="text-secondary small">Guess images</div><div class="fs-4 fw-semibold">{{ $summary['guess_images'] }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="text-secondary small">Reveal images</div><div class="fs-4 fw-semibold">{{ $summary['reveal_images'] }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="text-secondary small">Missing files</div><div class="fs-4 fw-semibold {{ $summary['missing'] > 0 ? 'text-warning' : '' }}">{{ $summary['missing'] }}</div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="small text-secondary">
            {{ $summary['files'] }} file(s), about {{ number_format($summary['bytes'] / 1048576, 1) }} MB. Limit: {{ $maxFiles }} files per export.
            @if($summary['missing'] > 0)
                <span class="text-warning">Missing files are listed in the manifest and skipped.</span>
            @endif
        </div>
        @if($summary['challenges'] === 0)
            <span class="badge text-bg-secondary">No challenges match these filters — nothing to export.</span>
        @elseif($summary['too_many'])
            <span class="badge text-bg-warning">Too many files ({{ $summary['files'] }} &gt; {{ $maxFiles }}). Narrow the filters first.</span>
        @else
            <form method="POST" action="{{ route('admin.media-export.download') }}" class="mb-0 ms-auto">
                @csrf
                @foreach($filters as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <button type="submit" class="btn btn-primary btn-sm">Download ZIP</button>
            </form>
        @endif
    </div>
</div>

@if($preview->isNotEmpty())
<div class="card">
    <div class="card-header small text-secondary">Preview — first {{ $preview->count() }} of {{ $summary['challenges'] }} matching challenge(s)</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>ID</th><th>Title</th><th>Sport</th><th>Pool</th><th>Status</th><th>Difficulty</th><th>Guess</th><th>Reveal</th><th>Daily</th><th>Packs</th><th>Updated</th>
                </tr>
            </thead>
            <tbody>
                @foreach($preview as $c)
                    <tr>
                        <td class="text-secondary">{{ $c->id }}</td>
                        <td>{{ $c->title }}</td>
                        <td>{{ $c->sport?->emoji }} {{ $c->sport?->name }}</td>
                        <td>{{ $c->usage_pool }}</td>
                        <td>{{ $c->status }}</td>
                        <td>{{ $c->difficulty }}</td>
                        <td>{{ $c->hidden_image_path ? '✓' : '—' }}</td>
                        <td>{{ $c->original_image_path ? '✓' : '—' }}</td>
                        <td>{{ $c->daily_challenges_count > 0 ? '✓' : '—' }}</td>
                        <td>{{ $c->packs_count }}</td>
                        <td class="text-secondary small">{{ $c->updated_at?->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
