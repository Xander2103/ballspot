@extends('public.layout')
@section('title', $ok ? 'Password updated' : ($state === 'failed' ? 'Please try again' : 'Reset link expired'))

@section('content')
<style>
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 52px; border-radius: 12px; border: 0; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #041017; }
    .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); margin-top: 0.75rem; }
    .status-icon { font-size: 3rem; margin-bottom: 0.5rem; }
</style>

@if($ok)
    <div class="status-icon">✅</div>
    <h1>Password updated</h1>
    <p class="page-meta">Your BallPicker password has been changed and every other session has been signed out.</p>
    <div class="callout">
        <p>Open the BallPicker app and log in with your new password.</p>
    </div>
@elseif($state === 'failed')
    <div class="status-icon">⚠️</div>
    <h1>Please try again</h1>
    <p class="page-meta">{{ $message }}</p>
    <div class="callout">
        <p>Nothing was changed: your current password still works and this reset link is still valid.</p>
    </div>
    @if($retryUrl)
        <a class="btn btn-primary" href="{{ $retryUrl }}">Try again</a>
    @endif
    <a class="btn btn-secondary" href="{{ route('password.request') }}">Request a new link</a>
@else
    <div class="status-icon">⏰</div>
    <h1>This link no longer works</h1>
    <p class="page-meta">{{ $message }}</p>
    <div class="callout">
        <p>Reset links are valid for a limited time and can only be used once. Request a new one and use the newest email.</p>
    </div>
    <a class="btn btn-primary" href="{{ route('password.request') }}">Request a new link</a>
@endif
@endsection
