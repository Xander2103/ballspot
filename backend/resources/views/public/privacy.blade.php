@extends('public.layout')
@section('title', 'Privacy Policy')

@section('content')
<h1>Privacy Policy</h1>
<p class="page-meta">Last updated: June 2026</p>

<p>BallPicker is a skill-based football guessing game. This policy explains what personal information we collect, how we use it, and your rights regarding that information.</p>

<h2>What we collect</h2>
<ul>
    <li><strong>Account information:</strong> name, email address, and username when you register.</li>
    <li><strong>Gameplay data:</strong> your guesses, scores, and round results within tournaments and daily challenges.</li>
    <li><strong>Tournament membership:</strong> which tournaments you have joined or created.</li>
    <li><strong>Technical data:</strong> basic request metadata (IP address, device type, app version) for security and abuse prevention.</li>
</ul>

<h2>How we use it</h2>
<ul>
    <li>To operate your account and authenticate you in the app.</li>
    <li>To run gameplay — calculating scores, showing leaderboards, displaying results.</li>
    <li>To show daily and weekly challenge leaderboards.</li>
    <li>To detect and prevent abuse or security issues.</li>
</ul>

<h2>What we do not do</h2>
<ul>
    <li>We do not sell your personal data to third parties.</li>
    <li>We do not run advertisements within the app.</li>
    <li>We do not involve real money, prizes, or gambling of any kind.</li>
    <li>We do not share your data with other users beyond your display name and scores on leaderboards.</li>
</ul>

<h2>Challenge images</h2>
<p>All football challenge images used in BallPicker are managed and uploaded by the BallPicker team via the admin panel. If you believe any image infringes your rights, please contact us at the address below.</p>

<h2>Data retention</h2>
<p>Your data is kept for as long as your account is active. If you delete your account, your personal details (name, email, username) are immediately anonymised. Your historical scores may be retained in anonymised form to preserve leaderboard integrity.</p>

<h2>Deleting your account</h2>
<div class="callout">
    <p>You can delete your account at any time from <strong>Profile → Settings → Delete account</strong> in the BallPicker app. This immediately anonymises your personal information and revokes all login sessions.</p>
</div>

<h2>Contact</h2>
<p>Questions about this policy or your data? Email us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
