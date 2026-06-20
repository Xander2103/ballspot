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
        .badge-active { background: #0d6efd; }
        .badge-archived { background: #adb5bd; color: #343a40; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/admin/challenges">BallSpot Admin</a>
        <span class="text-secondary small">v1 MVP</span>
    </div>
</nav>
<div class="container pb-5">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmE5DOdR8fGOABGJxGBnI3VJiS4" crossorigin="anonymous"></script>
</body>
</html>
