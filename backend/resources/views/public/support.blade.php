@extends('public.layout')
@section('title', 'Support')

@section('content')
<h1>Support</h1>
<p class="page-meta">We're here to help.</p>

<div class="callout">
    <p>Email us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> — we aim to respond within 2 business days.</p>
</div>

<h2>Delete your account</h2>
<p>You can delete your account directly from the app:</p>
<ol style="padding-left: 1.4rem; color: var(--text-secondary); font-size: 0.95rem;">
    <li style="margin-bottom: 0.35rem;">Open the app and tap <strong>Profile</strong> (bottom navigation).</li>
    <li style="margin-bottom: 0.35rem;">Scroll down to <strong>Settings</strong>.</li>
    <li style="margin-bottom: 0.35rem;">Tap <strong>Delete account</strong> and confirm.</li>
</ol>
<p>Your personal information (name, email, username) will be anonymised immediately and all sessions will be revoked. If you cannot access the app, email us and we will delete your account manually.</p>

<h2>Reporting a bug</h2>
<p>If you find a bug, please email us with:</p>
<ul>
    <li>What you were doing when the bug occurred</li>
    <li>What you expected to happen</li>
    <li>What actually happened</li>
    <li>Your device and app version (shown in Profile → Settings)</li>
</ul>

<h2>Other questions</h2>
<p>For anything else — account issues, feedback, or content concerns — email <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
