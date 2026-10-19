{{-- Club logo or initial. Expects $club, optional $size ('lg'). --}}
@if($club->logo_path)
    <img src="{{ $club->logoUrl() }}" alt="" class="club-logo {{ ($size ?? '') === 'lg' ? 'club-logo-lg' : '' }}" loading="lazy">
@else
    <span class="club-logo {{ ($size ?? '') === 'lg' ? 'club-logo-lg' : '' }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($club->name, 0, 1)) }}</span>
@endif
