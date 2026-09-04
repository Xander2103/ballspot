@extends('public.layout')
@section('title', 'Reset password')

@section('content')
<style>
    .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem 1.25rem; margin: 1.5rem 0; }
    .field { margin-bottom: 1rem; }
    .field label { display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem; font-weight: 600; }
    .field input { width: 100%; height: 48px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text); padding: 0 0.9rem; font-size: 1rem; }
    .field input:focus { outline: 2px solid var(--primary); border-color: var(--primary); }
    .field-error { color: #ff6b6b; font-size: 0.85rem; margin-top: 0.35rem; }
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 52px; border-radius: 12px; border: 0; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #041017; }
    .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); margin-top: 0.75rem; }
    .muted { color: var(--text-secondary); font-size: 0.85rem; margin-top: 1rem; }
</style>

<h1>Reset your password</h1>
<p class="page-meta">Choose a new password for your BallPicker account.</p>

@if(!$token)
    <div class="callout">
        <p>This page needs the link from your password reset email. If the link no longer works, request a new one below.</p>
    </div>
    <a class="btn btn-primary" href="{{ route('password.request') }}">Request a new link</a>
@else
    <div class="form-card">
        <form method="POST" action="{{ route('password.update') }}" autocomplete="off">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="password">New password (at least 8 characters)</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
            </div>

            @error('token')<div class="field-error">{{ $message }}</div>@enderror

            <button class="btn btn-primary" type="submit">Set new password</button>
        </form>
    </div>

    @if($deepLink)
        <a class="btn btn-secondary" href="{{ $deepLink }}">Open in the BallPicker app instead</a>
        <p class="muted">Only works on a phone with BallPicker installed. Otherwise just use the form above — after saving, open the app and log in with your new password.</p>
    @endif

    <p class="muted">Link not working? <a class="inline-link" href="{{ route('password.request') }}">Request a new link</a>.</p>
@endif
@endsection
