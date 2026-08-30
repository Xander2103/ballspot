@php $elig = $challenge->tournamentEligibility(); @endphp
<tr class="{{ $challenge->used_as_daily ? 'row-daily-locked' : '' }}">
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
        <span class="badge bg-pool-{{ $challenge->usage_pool }}" title="Usage pool">{{ ucfirst($challenge->usage_pool) }}</span>
        @if($challenge->used_as_daily)
            <div class="mt-1">
                <span class="badge bg-danger" title="Excluded from tournaments">🔒 Used as Daily</span>
                <div class="text-muted" style="font-size:.7rem">Excluded from tournaments</div>
            </div>
        @endif
    </td>
    <td>
        <span class="badge {{ $elig['class'] }}" title="{{ $elig['eligible'] ? 'Can be drawn into new tournaments' : 'Will not be drawn into new tournaments' }}">{{ $elig['label'] }}</span>
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
