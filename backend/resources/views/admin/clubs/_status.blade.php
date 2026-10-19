{{-- Club status badge. Expects $club. --}}
@php $st = $club->status->value; @endphp
@if($st === 'active')
    <span class="badge badge-ok">Active</span>
@elseif($st === 'suspended')
    <span class="badge badge-suspended" title="{{ $club->suspension_reason }}">Suspended</span>
@else
    <span class="badge badge-archived" title="{{ $club->archived_reason }}">Archived</span>
@endif
@if($club->needs_owner && $st !== 'archived')
    <span class="badge badge-needs-owner" title="No active owner — add or appoint one on the Members tab">Needs owner</span>
@endif
