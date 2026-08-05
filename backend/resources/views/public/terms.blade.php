@extends('public.layout')
@section('title', 'Terms of Service')

@section('content')
<h1>Terms of Service</h1>
<p class="page-meta">Last updated: August 2026</p>

<p>By using BallPicker you agree to these terms. If you do not agree, do not use the app. BallPicker is operated by [OPERATOR LEGAL NAME], [ADDRESS], [COUNTRY].</p>

<h2>Minimum age</h2>
<p>You must be at least {{ $minimumAge }} years old to create a BallPicker account. You confirm your age when you register. If we learn that an account belongs to someone below that age, we will remove it.</p>

<h2>What BallPicker is</h2>
<p>BallPicker is a skill-based entertainment game. You look at football images and guess where a hidden ball is located. Points are awarded based on accuracy. It is purely a game of skill and observation — there are no prizes, no gambling, and no real money involved.</p>

<h2>No real money or prizes</h2>
<ul>
    <li>BallPicker does not involve real money, wagering, or gambling of any kind.</li>
    <li>Scores and leaderboard positions carry no monetary value.</li>
    <li>No prizes are guaranteed or offered for any performance in the app.</li>
</ul>

<h2>Fair use</h2>
<ul>
    <li>You agree to play honestly. Using scripts, bots, or any automated tools to submit guesses is not allowed.</li>
    <li>You may not attempt to exploit bugs or glitches in gameplay.</li>
    <li>Sharing account credentials with others is not permitted.</li>
</ul>

<h2>Account</h2>
<p>You are responsible for keeping your account details secure. You may delete your account at any time from within the app. We reserve the right to suspend or remove accounts that violate these terms.</p>

<h2>App changes</h2>
<p>BallPicker may change, update, or discontinue features at any time. We will try to communicate significant changes, but cannot guarantee advance notice in all cases.</p>

<h2>Content</h2>
<p>Challenge images are managed by the BallPicker team. We aim to use appropriately licensed imagery. If you believe any content infringes your rights, please contact us immediately.</p>

<h2>Your content and conduct</h2>
<p>Your display name, username and profile picture are visible to other signed-in players. You are responsible for what you upload, and you agree not to use:</p>
<ul>
    <li>sexually explicit, violent, hateful or otherwise objectionable images or names;</li>
    <li>content that impersonates another person or organisation;</li>
    <li>content that infringes someone else's copyright, trademark or privacy;</li>
    <li>names or images containing harassment, slurs, or unlawful material.</li>
</ul>
<p><strong>We do not tolerate objectionable content or abusive behaviour.</strong> If you see a profile picture, username or other content that breaks these rules, report it to <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> with the player's username. We aim to review reports within 24 hours and will remove offending content and suspend or remove repeat offenders. You can also remove any player from your friends list at any time from the Friends screen, which stops them appearing in your app.</p>

<h2>Limitation of liability</h2>
<p>BallPicker is provided "as is." We are not liable for any loss of data, interruption of service, or any indirect damages arising from your use of the app.</p>

<h2>Contact</h2>
<p>Questions about these terms? Email us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
