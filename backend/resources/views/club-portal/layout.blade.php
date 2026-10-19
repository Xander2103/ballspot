@php
    /** @var \App\Models\Club|null $portalClub */
    $portalClub    = $portalClub ?? null;
    $portalUser    = $portalUser ?? auth()->user();
    $portalClubs   = $portalClubs ?? collect();
    $portalRole    = $portalRole ?? null;
    $portalIsOwner = $portalIsOwner ?? false;
    $guestPage     = $guestPage ?? false;
    $nav = $portalClub ? array_filter([
        ['club-portal.dashboard', 'dashboard', 'club-portal.dashboard'],
        ['club-portal.members.index', 'members', 'club-portal.members.*'],
        ['club-portal.codes.index', 'codes', 'club-portal.codes.*'],
        ['club-portal.photos.index', 'photos', 'club-portal.photos.*'],
        \Illuminate\Support\Facades\Route::has('club-portal.competitions.index') ? ['club-portal.competitions.index', 'competitions', 'club-portal.competitions.*'] : null,
        ['club-portal.leaderboard.index', 'leaderboard', 'club-portal.leaderboard.*'],
        $portalIsOwner ? ['club-portal.settings.edit', 'settings', 'club-portal.settings.*'] : null,
        ['club-portal.audit.index', 'audit', 'club-portal.audit.*'],
    ]) : [];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') – {{ __('club_portal.portal_name') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root { --portal-ink: #0b1f3a; --portal-green: #00a845; --portal-blue: #0d6efd; }
        body { background: #f5f7fa; min-height: 100vh; display: flex; flex-direction: column; }
        main { flex: 1 0 auto; }
        .portal-bar { background: var(--portal-ink); }
        .portal-bar .navbar-brand { font-weight: 800; color: #fff; letter-spacing: -.2px; }
        .portal-bar .navbar-brand span { color: #4ade80; }
        .portal-bar .nav-meta { color: rgba(255,255,255,.7); font-size: .85rem; }
        .portal-club { background: #fff; border-bottom: 1px solid #dee2e6; }
        .portal-club .club-name { font-weight: 700; color: var(--portal-ink); }
        .portal-tabs { overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; flex-wrap: nowrap; }
        .portal-tabs .nav-link { color: #344054; font-weight: 500; border-bottom: 2px solid transparent; border-radius: 0; padding: .65rem .85rem; }
        .portal-tabs .nav-link.active { color: var(--portal-blue); font-weight: 700; border-bottom-color: var(--portal-blue); background: transparent; }
        .portal-card { background: #fff; border: 1px solid #e4e7ec; border-radius: .75rem; }
        .stat-value { font-size: 1.6rem; font-weight: 800; line-height: 1.1; color: var(--portal-ink); }
        .stat-label { font-size: .8rem; color: #667085; }
        .thumb { width: 72px; height: 54px; object-fit: cover; border-radius: 6px; background: #e9ecef; display: block; }
        .thumb-missing { width: 72px; height: 54px; border: 1px dashed #ced4da; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: .7rem; }
        .badge-role-owner { background: #fde68a; color: #713f12; }
        .badge-role-admin { background: #dbeafe; color: #1e3a8a; }
        .badge-role-member { background: #e9ecef; color: #495057; }
        .badge-soft-ok { background: #d1e7dd; color: #0f5132; }
        .badge-soft-warn { background: #fff3cd; color: #664d03; }
        .badge-soft { background: #e9ecef; color: #495057; }
        .badge-soft-info { background: #cfe2ff; color: #084298; }
        .badge-soft-danger { background: #f8d7da; color: #842029; }
        .auth-card { max-width: 420px; margin: 3rem auto; }
        .code-input { font-size: 1.6rem; letter-spacing: .5em; text-align: center; font-family: SFMono-Regular, Menlo, Consolas, monospace; }
        .table td, .table th { vertical-align: middle; }
        .table th { white-space: nowrap; font-size: .8rem; color: #667085; font-weight: 600; }
        footer { color: #98a2b3; font-size: .8rem; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark portal-bar">
    <div class="container-lg">
        <a class="navbar-brand" href="{{ $guestPage ? route('club-portal.login') : route('club-portal.home') }}">BallPicker <span>Club Portal</span></a>
        @unless($guestPage)
            @if($portalUser)
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portal-user-nav" aria-controls="portal-user-nav" aria-expanded="false" aria-label="{{ __('club_portal.nav.menu') }}">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse justify-content-end" id="portal-user-nav">
                    <ul class="navbar-nav align-items-md-center gap-md-2 mt-2 mt-md-0">
                        @if($portalClubs->count() > 1)
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" data-section="club-switcher">{{ __('club_portal.nav.switch_club') }}</a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @foreach($portalClubs as $c)
                                        <li><a class="dropdown-item {{ $portalClub && $portalClub->id === $c->id ? 'active' : '' }}" href="{{ route('club-portal.dashboard', $c) }}">{{ $c->name }} <span class="small text-muted">· {{ __('club_portal.roles.' . $c->portal_role->value) }}</span></a></li>
                                    @endforeach
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="{{ route('club-portal.home') }}">{{ __('club_portal.nav.all_clubs') }}</a></li>
                                </ul>
                            </li>
                        @endif
                        <li class="nav-item"><span class="nav-meta">{{ __('club_portal.nav.signed_in_as', ['name' => $portalUser->username]) }}</span></li>
                        <li class="nav-item">
                            <form method="POST" action="{{ route('club-portal.logout') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-light btn-sm">{{ __('club_portal.nav.logout') }}</button>
                            </form>
                        </li>
                    </ul>
                </div>
            @endif
        @endunless
    </div>
</nav>

@if($portalClub)
    <div class="portal-club">
        <div class="container-lg pt-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                @if($portalClub->logoUrl())<img src="{{ $portalClub->logoUrl() }}" alt="" width="32" height="32" class="rounded">@endif
                <span class="club-name h5 mb-0">{{ $portalClub->name }}</span>
                @if($portalRole)<span class="badge badge-role-{{ $portalRole->value }}">{{ __('club_portal.roles.' . $portalRole->value) }}</span>@endif
                @if($portalClub->isSuspended())<span class="badge bg-warning text-dark">{{ __('club_portal.club_status.suspended') }}</span>@endif
            </div>
            <ul class="nav portal-tabs mt-2" role="navigation">
                @foreach($nav as [$route, $key, $pattern])
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}" href="{{ route($route, $portalClub) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ __('club_portal.nav.' . $key) }}</a></li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<main class="container-lg py-4">
    @if($portalClub && $portalClub->isSuspended())
        <div class="alert alert-warning" data-section="suspended-banner">{{ __('club_portal.common.suspended_banner') }}</div>
    @endif
    @foreach(['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $flash => $cls)
        @if(session($flash))
            <div class="alert alert-{{ $cls }} alert-dismissible fade show" role="alert">
                {{ session($flash) }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('club_portal.common.close') }}"></button>
            </div>
        @endif
    @endforeach

    @yield('content')
</main>

<footer class="container-lg pb-4">
    {{ __('club_portal.portal_name') }}@if($portalClub) · {{ __('club_portal.common.platform_only') }}@endif
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmE5DOdR8fGOABGJxGBnI3VJiS4" crossorigin="anonymous"></script>
@stack('scripts')
</body>
</html>
