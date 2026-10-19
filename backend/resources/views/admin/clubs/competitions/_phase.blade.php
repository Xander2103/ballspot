{{-- Phase badge of a competition. Expects $competition. --}}
@php
    $phaseClass = ['draft' => 'badge-draft', 'upcoming' => 'badge-scheduled', 'active' => 'badge-active', 'ended' => 'badge-soft-warn', 'finalized' => 'badge-ok', 'cancelled' => 'badge-archived', 'voided' => 'badge-archived'];
    $phase = $competition->phase()->value;
@endphp
<span class="badge {{ $phaseClass[$phase] ?? 'badge-soft' }}" data-phase="{{ $phase }}">{{ ucfirst($phase) }}</span>@if($competition->isLocked() && !in_array($phase, ['draft', 'cancelled'], true)) <span title="Rules locked" aria-label="Rules locked">🔒</span>@endif
