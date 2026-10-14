@php
    $elig = $challenge->tournamentEligibility();
    // Availability = the stored usage pool (one value per challenge: daily |
    // tournament | pack | general) plus the computed tournament eligibility.
    // "Daily only" / "Pack only" restate the pool, so they live in the pool
    // badge's tooltip; the other reasons add information and get a badge.
    $poolTitles = [
        \App\Models\Challenge::POOL_DAILY      => 'Daily only — may only be scheduled as a Daily, never drawn into tournaments',
        \App\Models\Challenge::POOL_TOURNAMENT => 'Tournament — may only be drawn into tournament rounds',
        \App\Models\Challenge::POOL_PACK       => 'Pack only — curated pack content, never auto-selected for Daily or tournaments',
        \App\Models\Challenge::POOL_GENERAL    => 'General — eligible for Daily and tournaments until used as a Daily',
    ];
    $showEligibility = !in_array($challenge->usage_pool, [\App\Models\Challenge::POOL_DAILY, \App\Models\Challenge::POOL_PACK], true) && !$challenge->used_as_daily;
    $eligClass = $elig['eligible'] ? 'badge-soft-ok' : 'badge-soft-warn';
    $missing = [];
    if (empty($challenge->hidden_image_path)) $missing[] = 'hidden image';
    if ($challenge->ball_x_ratio === null || $challenge->ball_y_ratio === null) $missing[] = 'ball position';
    if (empty($challenge->title)) $missing[] = 'title';
    if ($challenge->sport_id === null) $missing[] = 'sport';
    $editUrl = '/admin/challenges/' . $challenge->id . '/edit';
@endphp
<tr class="{{ $challenge->used_as_daily ? 'row-daily-locked' : '' }}">
    <td>
        <div class="d-flex gap-1">
            @if($challenge->hidden_image_path)
                <span class="thumb-wrap" title="Hidden image (what players see)">
                    <img src="{{ asset('storage/' . $challenge->hidden_image_path) }}" alt="Hidden image for {{ $challenge->title }}" class="thumb" loading="lazy">
                    <span class="thumb-tag" aria-hidden="true">H</span>
                </span>
            @else
                <span class="thumb-missing text-danger" title="No hidden image">No hidden<br>image</span>
            @endif
            @if($challenge->original_image_path)
                <span class="thumb-wrap" title="Reveal image (ball visible)">
                    <img src="{{ asset('storage/' . $challenge->original_image_path) }}" alt="Reveal image for {{ $challenge->title }}" class="thumb" loading="lazy">
                    <span class="thumb-tag" aria-hidden="true">R</span>
                </span>
            @else
                <span class="thumb-missing text-muted" title="No reveal image">No reveal</span>
            @endif
        </div>
    </td>
    <td>
        <a href="{{ $editUrl }}" class="challenge-title">{{ $challenge->title }}</a>
        <div class="text-muted small challenge-meta">
            {{ $challenge->category?->name ?? '—' }}
            @if($challenge->sport)<span class="text-muted"> · {{ $challenge->sport->name }}</span>@endif
            @if($challenge->isDemoContent())
                <span class="badge badge-soft-warn ms-1" title="This is placeholder demo content">Demo</span>
            @endif
        </div>
        @if($challenge->subcategories->isNotEmpty())
            <div class="mt-1 small">
                @foreach($challenge->subcategories as $sub)
                    <span class="badge rounded-pill bg-light text-dark border fw-normal" style="{{ $sub->color ? 'border-color:'.$sub->color.' !important;' : '' }}">{{ $sub->icon ? $sub->icon.' ' : '' }}{{ $sub->name }}</span>
                @endforeach
            </div>
        @endif
    </td>
    <td>
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="badge bg-pool-{{ $challenge->usage_pool }}" title="{{ $poolTitles[$challenge->usage_pool] ?? 'Usage pool' }}">{{ ucfirst($challenge->usage_pool) }}</span>
            @if($showEligibility)
                <span class="badge {{ $eligClass }}" title="{{ $elig['eligible'] ? 'Can be drawn into new tournaments' : 'Will not be drawn into new tournaments' }}">{{ $elig['label'] }}</span>
            @endif
        </div>
        @if($challenge->used_as_daily)
            <div class="mt-1">
                <span class="badge bg-danger" title="{{ $elig['label'] }} — will not be drawn into new tournaments">🔒 Used as Daily</span>
                <div class="text-muted" style="font-size:.7rem">Excluded from tournaments</div>
            </div>
        @endif
    </td>
    <td>
        @if($challenge->isReady())
            <span class="badge badge-ok">Ready</span>
        @else
            <span class="badge bg-danger" title="Missing: {{ implode(', ', $missing) }}">Incomplete</span>
            <div class="text-danger" style="font-size:.7rem">Missing {{ implode(', ', $missing) }}</div>
        @endif
    </td>
    <td>
        <span class="badge badge-{{ $challenge->difficulty }}">{{ ucfirst($challenge->difficulty) }}</span>
    </td>
    <td>
        <span class="badge badge-{{ $challenge->status }}">{{ ucfirst($challenge->status) }}</span>
    </td>
    <td class="small text-muted text-nowrap">
        @if($challenge->ball_x_ratio !== null)
            <span title="Ball position — X: {{ round($challenge->ball_x_ratio * 100) }}% · Y: {{ round($challenge->ball_y_ratio * 100) }}%">{{ round($challenge->ball_x_ratio * 100) }}%, {{ round($challenge->ball_y_ratio * 100) }}%</span>
        @else
            <span class="text-danger" title="No ball position set">—</span>
        @endif
    </td>
    <td class="text-end">
        <div class="btn-group btn-group-sm" role="group" aria-label="Actions for {{ $challenge->title }}">
            <a href="/admin/challenges/{{ $challenge->id }}/preview" class="btn btn-outline-secondary">Preview</a>
            <a href="{{ $editUrl }}" class="btn btn-outline-secondary">Edit</a>
            @php
                $canActivate = $challenge->status !== 'active' && $challenge->isReady();
                $canDraft    = $challenge->status === 'archived' || $challenge->status === 'active';
                $canArchive  = $challenge->status !== 'archived';
            @endphp
            @if($canActivate || $canDraft || $canArchive)
            <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $challenge->title }}">
                <span aria-hidden="true">…</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                @if($canActivate)
                <li>
                    <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST">
                        @csrf
                        <input type="hidden" name="status" value="active">
                        <button type="submit" class="dropdown-item">Activate</button>
                    </form>
                </li>
                @endif
                @if($canDraft)
                <li>
                    <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST">
                        @csrf
                        <input type="hidden" name="status" value="draft">
                        <button type="submit" class="dropdown-item" onclick="return confirm('Move \'{{ addslashes($challenge->title) }}\' to Draft? It stops being served until it is activated again.')">Move to Draft</button>
                    </form>
                </li>
                @endif
                @if($canArchive)
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="/admin/challenges/{{ $challenge->id }}/status" method="POST">
                        @csrf
                        <input type="hidden" name="status" value="archived">
                        <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Archive this challenge?')">Archive</button>
                    </form>
                </li>
                @endif
            </ul>
            @endif
        </div>
    </td>
</tr>
