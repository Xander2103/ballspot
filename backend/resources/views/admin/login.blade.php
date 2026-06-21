<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BallSpot Admin – Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>body { background: #f8f9fa; }</style>
</head>
<body>
<div class="d-flex justify-content-center align-items-center" style="min-height:100vh;">
    <div class="card shadow-sm" style="width:360px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 fw-bold">BallSpot Admin</h1>
            <p class="text-muted small mb-4">Sign in to manage challenges</p>

            @if($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.submit') }}">
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="{{ old('email') }}" autocomplete="email" required>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
