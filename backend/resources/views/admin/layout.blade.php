<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BallSpot Admin – @yield('title', 'Dashboard')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { background: #f8f9fa; }
        .navbar-brand { font-weight: 700; letter-spacing: .5px; }
        .table th { white-space: nowrap; }
        .badge-easy { background: #198754; }
        .badge-medium { background: #fd7e14; }
        .badge-hard { background: #dc3545; }
        .badge-draft { background: #6c757d; }
        .badge-scheduled { background: #6f42c1; }
        .badge-active { background: #0d6efd; }
        .badge-archived { background: #adb5bd; color: #343a40; }
        /* Usage pools (v1.8.9 fairness) */
        .bg-pool-daily { background: #6f42c1 !important; color: #fff; }
        .bg-pool-tournament { background: #198754 !important; color: #fff; }
        .bg-pool-pack { background: #fd7e14 !important; color: #fff; }
        .bg-pool-general { background: #6c757d !important; color: #fff; }
        tr.row-daily-locked td { background: #f8f9fa; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container d-flex align-items-center gap-3">
        <a class="navbar-brand me-2" href="/admin/challenges">BallSpot Admin</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/challenges*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/challenges">Challenges</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/categories*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/categories">Categories</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/subcategories*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/subcategories">Subcategories</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/packs*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/packs">Packs</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/sports*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/sports">Sports</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/daily*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/daily">Daily</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/competition*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/competition">Competition</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/notifications*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/notifications">Notifications</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/settings*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/settings">Settings</a>
        <a class="text-white-50 small text-decoration-none {{ request()->is('admin/diagnostics*') ? 'text-white fw-semibold' : '' }}"
           href="/admin/diagnostics">Diagnostics</a>
        <span class="text-secondary small ms-auto me-3">{{ config('ballspot.version', 'v1') }}</span>
        <form action="{{ route('admin.logout') }}" method="POST" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary text-white">Logout</button>
        </form>
    </div>
</nav>
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
