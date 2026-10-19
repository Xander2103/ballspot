{{-- Phase badge. Expects $competition. --}}
@php
    $phaseClass = ['draft' => 'badge-soft', 'upcoming' => 'badge-soft-info', 'active' => 'bg-success', 'ended' => 'badge-soft-warn', 'finalized' => 'badge-soft-ok', 'cancelled' => 'badge-soft', 'voided' => 'badge-soft-danger'];
    $phase = $competition->phase()->value;
@endphp
<span class="badge {{ $phaseClass[$phase] ?? 'badge-soft' }}" data-phase="{{ $phase }}">{{ __('club_portal.competitions.phase.' . $phase) }}</span>@if($competition->isLocked() && !in_array($phase, ['draft', 'cancelled'], true)) <span title="{{ __('club_portal.competitions.locked_badge') }}" aria-label="{{ __('club_portal.competitions.locked_badge') }}">🔒</span>@endif
