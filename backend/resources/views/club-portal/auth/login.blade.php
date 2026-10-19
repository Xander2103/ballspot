@extends('club-portal.layout', ['guestPage' => true])

@section('title', __('club_portal.auth.login_title'))

@section('content')
<div class="auth-card">
    <div class="portal-card p-4 shadow-sm">
        <h1 class="h4 mb-2">{{ __('club_portal.auth.login_heading') }}</h1>
        <p class="text-muted small">{{ __('club_portal.auth.login_intro') }}</p>
        <form method="POST" action="{{ route('club-portal.login.submit') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label for="email" class="form-label">{{ __('club_portal.auth.email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255" class="form-control @error('email') is-invalid @enderror">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">{{ __('club_portal.auth.password') }}</label>
                <input id="password" type="password" name="password" required autocomplete="current-password" maxlength="255" class="form-control @error('password') is-invalid @enderror">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary w-100">{{ __('club_portal.auth.submit') }}</button>
        </form>
        <p class="small text-muted mt-3 mb-1">{{ __('club_portal.auth.code_note') }}</p>
        <a href="{{ route('password.request', ['lang' => app()->getLocale()]) }}" class="small">{{ __('club_portal.auth.forgot') }}</a>
    </div>
</div>
@endsection
