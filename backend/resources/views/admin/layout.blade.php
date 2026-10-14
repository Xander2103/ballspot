<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BallSpot Admin – @yield('title', 'Dashboard')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root {
            --admin-navy: #0b1f3a;
            --admin-navy-text: #1b2a44;
            --admin-blue: #0d6efd;
        }
        body { background: #f8f9fa; }
        .table th { white-space: nowrap; }

        /* ---- Two-level navigation (v1.9.14) ------------------------------ */
        .admin-nav { background: var(--admin-navy); }
        .admin-nav .navbar-brand { font-weight: 700; letter-spacing: .5px; color: #fff; }
        .admin-nav .admin-nav-link {
            color: rgba(255,255,255,.72); font-size: .9rem; font-weight: 500; text-decoration: none;
            padding: .85rem .9rem; border-bottom: 3px solid transparent; white-space: nowrap;
        }
        .admin-nav .admin-nav-link:hover, .admin-nav .admin-nav-link:focus-visible { color: #fff; }
        .admin-nav .admin-nav-link.active {
            color: #fff; font-weight: 700; background: rgba(255,255,255,.10); border-bottom-color: var(--admin-blue);
        }
        .admin-nav .admin-nav-meta { color: rgba(255,255,255,.6); font-size: .8rem; }
        .admin-subnav {
            background: #fff; border-bottom: 1px solid #dee2e6; overflow-x: auto; white-space: nowrap;
            -webkit-overflow-scrolling: touch;
        }
        .admin-subnav-group { font-size: .72rem; letter-spacing: .5px; text-transform: uppercase; color: #6c757d; padding-right: .75rem; border-right: 1px solid #dee2e6; margin-right: .5rem; }
        .admin-subnav .admin-subnav-link {
            display: inline-block; color: var(--admin-navy-text); font-size: .9rem; font-weight: 500; text-decoration: none;
            padding: .75rem .85rem; border-bottom: 2px solid transparent; margin-bottom: -1px;
        }
        .admin-subnav .admin-subnav-link:hover, .admin-subnav .admin-subnav-link:focus-visible { color: var(--admin-blue); }
        .admin-subnav .admin-subnav-link.active { color: var(--admin-blue); font-weight: 700; border-bottom-color: var(--admin-blue); }

        /* ---- Calm badge palette: strong colour only for meaningful states --- */
        .badge { font-weight: 600; }
        .badge-easy   { background: #d1e7dd; color: #0f5132; }
        .badge-medium { background: #fff3cd; color: #664d03; }
        .badge-hard   { background: #f8d7da; color: #842029; }
        .badge-draft    { background: #e9ecef; color: #495057; }
        .badge-scheduled { background: #6f42c1; }
        .badge-active   { background: var(--admin-blue); color: #fff; }
        .badge-archived { background: #f1f3f5; color: #6c757d; border: 1px solid #dee2e6; }
        .badge-ok    { background: #198754; color: #fff; }
        .badge-soft-ok { background: #d1e7dd; color: #0f5132; }
        .badge-soft-warn { background: #fff3cd; color: #664d03; }
        .badge-soft { background: #e9ecef; color: #495057; }
        /* Usage pools (v1.8.9 fairness) — tinted, not saturated */
        .bg-pool-daily      { background: #e7defa !important; color: #4b2d8f; }
        .bg-pool-tournament { background: #d8f0e2 !important; color: #0f5132; }
        .bg-pool-pack       { background: #ffe5cc !important; color: #7a3e00; }
        .bg-pool-general    { background: #e9ecef !important; color: #343a40; }
        tr.row-daily-locked td { background: #f8f9fa; }

        /* ---- Challenge table ------------------------------------------- */
        .admin-stat { border: 1px solid #dee2e6; border-radius: .5rem; background: #fff; padding: .5rem .75rem; text-decoration: none; display: block; color: inherit; }
        .admin-stat:hover { border-color: #adb5bd; color: inherit; }
        .admin-stat .admin-stat-value { font-size: 1.25rem; font-weight: 700; line-height: 1.2; }
        .admin-stat .admin-stat-label { font-size: .75rem; color: #6c757d; }
        .admin-segments .nav-link { color: var(--admin-navy-text); font-size: .875rem; padding: .4rem .75rem; border-bottom: 2px solid transparent; }
        .admin-segments .nav-link.active { color: var(--admin-blue); font-weight: 700; border-bottom-color: var(--admin-blue); background: transparent; }
        .admin-segments .nav-link .badge { font-weight: 600; }
        .thumb { width: 64px; height: 48px; object-fit: cover; border-radius: 4px; display: block; background: #e9ecef; }
        .thumb-wrap { position: relative; display: inline-block; }
        .thumb-tag { position: absolute; left: 3px; bottom: 3px; font-size: .6rem; font-weight: 700; line-height: 1; padding: 2px 4px; border-radius: 3px; background: rgba(11,31,58,.85); color: #fff; }
        .thumb-missing { width: 64px; height: 48px; border: 1px dashed #dee2e6; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: .65rem; text-align: center; }
        .challenge-title { color: var(--admin-navy-text); font-weight: 600; text-decoration: none; }
        .challenge-title:hover, .challenge-title:focus-visible { color: var(--admin-blue); text-decoration: underline; }
        .table-challenges { font-size: .875rem; }
        .table-challenges td { vertical-align: middle; }
        .table-challenges .challenge-meta { white-space: nowrap; }
        .table-challenges tbody tr:hover td { background: #f1f5fb; }
    </style>
</head>
<body>
@php
    // Two-level navigation: a primary group and its pages. Active state is
    // derived from the current URL (request()->is), never set per page.
    // URLs stay exactly as they are — this is presentation only.
    $navGroups = [
        'challenges' => ['label' => 'Challenges', 'url' => '/admin/challenges', 'match' => ['admin/challenges*'], 'pages' => []],
        'content' => ['label' => 'Content', 'url' => '/admin/categories', 'match' => ['admin/categories*', 'admin/subcategories*', 'admin/packs*', 'admin/sports*'], 'pages' => [
            ['label' => 'Challenges',    'url' => '/admin/challenges',    'match' => 'admin/challenges*'],
            ['label' => 'Categories',    'url' => '/admin/categories',    'match' => 'admin/categories*'],
            ['label' => 'Subcategories', 'url' => '/admin/subcategories', 'match' => 'admin/subcategories*'],
            ['label' => 'Packs',         'url' => '/admin/packs',         'match' => 'admin/packs*'],
            ['label' => 'Sports',        'url' => '/admin/sports',        'match' => 'admin/sports*'],
        ]],
        'game' => ['label' => 'Game', 'url' => '/admin/daily', 'match' => ['admin/daily*', 'admin/competition*'], 'pages' => [
            ['label' => 'Daily',       'url' => '/admin/daily',       'match' => 'admin/daily*'],
            ['label' => 'Competition', 'url' => '/admin/competition', 'match' => 'admin/competition*'],
        ]],
        'communication' => ['label' => 'Communication', 'url' => '/admin/notifications', 'match' => ['admin/notifications*', 'admin/notices*'], 'pages' => [
            ['label' => 'Notifications', 'url' => '/admin/notifications', 'match' => 'admin/notifications*'],
            ['label' => 'Notices',       'url' => '/admin/notices',       'match' => 'admin/notices*'],
        ]],
        'system' => ['label' => 'System', 'url' => '/admin/settings', 'match' => ['admin/settings*', 'admin/diagnostics*', 'admin/growth*', 'admin/media-export*'], 'pages' => [
            ['label' => 'Settings',     'url' => '/admin/settings',     'match' => 'admin/settings*'],
            ['label' => 'Diagnostics',  'url' => '/admin/diagnostics',  'match' => 'admin/diagnostics*'],
            ['label' => 'Growth',       'url' => '/admin/growth',       'match' => 'admin/growth*'],
            ['label' => 'Media Export', 'url' => '/admin/media-export', 'match' => 'admin/media-export*'],
        ]],
    ];
    $activeGroupKey = null;
    foreach ($navGroups as $key => $group) {
        if (request()->is(...$group['match'])) { $activeGroupKey = $key; break; }
    }
    $activeGroup = $activeGroupKey ? $navGroups[$activeGroupKey] : null;
    $adminName = auth()->user()?->name;
@endphp
<nav class="admin-nav navbar navbar-dark p-0 mb-0" aria-label="Admin sections">
    <div class="container d-flex align-items-stretch gap-1">
        <a class="navbar-brand me-3 py-2 align-self-center" href="/admin/challenges">BallSpot Admin</a>
        @foreach($navGroups as $key => $group)
            <a class="admin-nav-link {{ $activeGroupKey === $key ? 'active' : '' }}"
               href="{{ $group['url'] }}" @if($activeGroupKey === $key) aria-current="true" @endif>{{ $group['label'] }}</a>
        @endforeach
        <div class="ms-auto d-flex align-items-center gap-3 py-2">
            <span class="admin-nav-meta">{{ config('ballspot.version', 'v1') }}</span>
            @if($adminName)<span class="admin-nav-meta" title="Signed in as {{ $adminName }}">{{ $adminName }}</span>@endif
            <form action="{{ route('admin.logout') }}" method="POST" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
            </form>
        </div>
    </div>
</nav>
@if($activeGroup && $activeGroup['pages'] !== [])
<nav class="admin-subnav mb-4" aria-label="{{ $activeGroup['label'] }} pages">
    <div class="container d-flex align-items-center">
        <span class="admin-subnav-group">{{ $activeGroup['label'] }}</span>
        @foreach($activeGroup['pages'] as $page)
            @php $pageActive = request()->is($page['match']); @endphp
            <a class="admin-subnav-link {{ $pageActive ? 'active' : '' }}" href="{{ $page['url'] }}" @if($pageActive) aria-current="page" @endif>{{ $page['label'] }}</a>
        @endforeach
    </div>
</nav>
@else
<div class="mb-4"></div>
@endif
<div class="container pb-5">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            {{ session('warning') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmE5DOdR8fGOABGJxGBnI3VJiS4" crossorigin="anonymous"></script>
</body>
</html>
