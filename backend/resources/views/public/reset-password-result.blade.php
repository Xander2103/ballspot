@extends('public.layout')
@section('title', $ok ? 'Password updated' : 'Reset link expired')

@section('content')
<style>
    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 52px; border-radius: 12px; border: 0; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .btn-primary { background: var(--primary); color: #041017; }
    .status-icon { font-size: 3rem; margin-bottom: 0.5rem; }
</style>

@if($ok)
    <div class="status-icon">✅</div>
    <h1>Password updated</h1>
    <p class="page-meta">Your BallPicker password has been changed and every other session has been signed out.</p>
    <div class="callout">
        <p>Open the BallPicker app and log in with your new password.</p>
    </div>
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
