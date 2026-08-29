@extends('public.layout')
@section('title', 'Privacy Policy')

@section('content')
<h1>Privacy Policy</h1>
<p class="page-meta">Last updated: August 2026</p>

<p>BallPicker is a skill-based sports guessing game. This policy explains what personal information we process, why, how long we keep it, and what rights you have. We do not sell your data, we do not run advertisements, and we do not use advertising or analytics trackers.</p>

<div class="callout">
    <p><strong>Who is responsible for your data:</strong> BallPicker is created by Van Malder Studio and operated by Xander Van Malder, Belgium. For any privacy question or to exercise your rights, contact <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
</div>

<h2>What we collect</h2>
<ul>
    <li><strong>Account information:</strong> your name, username, email address, password (stored only as a secure hash), and whether your email has been verified. We also record when you accepted these terms.</li>
    <li><strong>Profile information:</strong> your profile picture (if you upload one), your chosen sport, and your chosen app theme.</li>
    <li><strong>Gameplay data:</strong> your guesses and their coordinates, scores, XP, rank, badges, streaks, tournaments and challenge packs you play, and your daily challenge history.</li>
    <li><strong>Friends data:</strong> your friend code, friend requests you send or receive (including declined ones), and your friend connections.</li>
    <li><strong>Notification data:</strong> if you enable notifications, a push notification token for each device, the device platform (iOS/Android), when it was last seen, your notification preferences and your timezone.</li>
    <li><strong>Security data:</strong> when you log in or verify your email we store a hashed one-time code together with the IP address and browser/app user agent used, to detect abuse. These records are short-lived.</li>
</ul>

<h2>How we use it, and our legal basis</h2>
<ul>
    <li><strong>Running your account and the game</strong> (accounts, gameplay, scores, leaderboards, tournaments, friends) — necessary to provide the service you asked for (contract).</li>
    <li><strong>Security and abuse prevention</strong> (login codes, IP/user-agent records, rate limiting) — our legitimate interest in keeping accounts and the game fair and secure.</li>
    <li><strong>Push notifications</strong> (daily and tournament reminders, friend and game updates, occasional announcements from us) — based on your consent, given through your device permission and the in-app notification settings. You can withdraw it at any time.</li>
    <li><strong>Email verification and password resets</strong> — necessary to operate and secure your account.</li>
</ul>

<h2>What other players can see</h2>
<p>BallPicker is a social game, so some of your information is visible to other signed-in players:</p>
<ul>
    <li>Your display name, username, profile picture, rank, XP, badge count and aggregate game statistics (such as tournaments played, number of guesses, total and average score, and daily challenge history) appear on leaderboards and on your player profile, which any signed-in player can view.</li>
    <li>Players you share your friend code or QR code with can send you a friend request.</li>
    <li><strong>Your email address is never shown to other players</strong>, and neither is your friend code, your push notification token, or any of your security data.</li>
</ul>

<h2>Push notifications</h2>
<p>If you allow notifications, we store a push notification token linked to your account for each device, along with the platform and when it was last used. We use it to send daily and tournament reminders, friend and gameplay updates, and occasional service announcements from us.</p>
<ul>
    <li>You can turn notifications off at any time in the app's notification settings or in your device settings.</li>
    <li>If you opt out of announcements, we do not send you optional announcements.</li>
    <li>Push tokens are never shown to other users, never included in your data export, and never sold or shared for marketing.</li>
    <li>Delivering a notification necessarily discloses it to the push infrastructure operated by Expo and by Apple (APNs) or Google (FCM).</li>
</ul>

<h2>Camera, photos and clipboard</h2>
<ul>
    <li>The camera is used <strong>only</strong> when you choose to scan a friend's QR code. Nothing is recorded, stored or uploaded, and the camera is never used in the background. You can always type a friend code by hand instead.</li>
    <li>Photo library access is used only when you choose to upload a profile picture.</li>
    <li>The clipboard is used only to copy your own friend code when you tap "Copy". The app never reads your clipboard.</li>
</ul>

<h2>Who we share data with</h2>
<p>We do not sell your personal data and we do not share it for advertising. We use a small number of service providers who process data on our behalf:</p>
<ul>
    <li><strong>Hetzner Online GmbH, Germany</strong> — hosting of the application and database.</li>
    <li><strong>Expo</strong> — push notification delivery, together with <strong>Apple Push Notification service</strong> and <strong>Google Firebase Cloud Messaging</strong>.</li>
    <li><strong>Zxcs B.V., Netherlands</strong> — delivery of verification, login and password-reset emails.</li>
    <li><strong>Apple App Store / Google Play</strong> — app distribution and, where applicable, TestFlight beta testing.</li>
</ul>
<p>Where a provider processes data outside the European Economic Area, that transfer is covered by the European Commission's Standard Contractual Clauses or an adequacy decision.</p>

<h2>How long we keep it</h2>
<ul>
    <li><strong>Account and gameplay data:</strong> until you delete your account (see below).</li>
    <li><strong>Push notification tokens:</strong> removed when you log out (where your device can reach our servers), when you remove the device, on account deletion, and automatically after about 90 days of inactivity.</li>
    <li><strong>Login and email verification codes:</strong> they expire within minutes and the records, including the IP address and user agent, are purged automatically.</li>
    <li><strong>Friend requests:</strong> kept until your account is deleted, including requests you have declined.</li>
    <li><strong>Backups:</strong> retained for a limited period and then overwritten.</li>
</ul>

<h2>Deleting your account</h2>
<div class="callout">
    <p>You can delete your account at any time from the <strong>Profile</strong> screen in the app, using the <strong>Delete account</strong> option at the bottom.</p>
    <p><strong>Please read this carefully, because it is not a complete erasure.</strong> When you delete your account we immediately:</p>
    <ul>
        <li>revoke all your login sessions and remove your push notification registrations and notification settings;</li>
        <li>delete your profile picture, your friend code, your friend connections and all your friend requests;</li>
        <li>replace your name, username and email address with anonymous placeholders and scramble your password, so the account can never be logged into again.</li>
    </ul>
    <p><strong>What remains:</strong> your gameplay history — guesses, scores, XP, badges, tournament results and tournaments you created — is kept in anonymised form, no longer linked to any identifying information, so that other players' leaderboards, tournaments and results stay intact. It appears as "Deleted User". If you need this residual data erased as well, contact us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> and we will handle it manually.</p>
</div>

<h2>Your rights</h2>
<p>Under the GDPR you have the right to access, correct, delete, restrict, object to and port your personal data, and to withdraw consent at any time. In the app you can:</p>
<ul>
    <li><strong>Access / port:</strong> download a machine-readable export of your data from the app. The export excludes security material such as password hashes and raw push tokens.</li>
    <li><strong>Correct:</strong> update your profile details.</li>
    <li><strong>Withdraw consent:</strong> turn off notifications at any time.</li>
    <li><strong>Delete:</strong> delete your account as described above.</li>
</ul>
<p>For anything else, email <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>. You also have the right to lodge a complaint with your national data protection authority.</p>

<h2>Children</h2>
<p>BallPicker is not intended for children. You must be at least {{ $minimumAge }} years old to create an account, and you confirm this when you register. If you believe a child below that age has created an account, contact us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> and we will remove it.</p>

<h2>Challenge images</h2>
<p>All challenge images used in BallPicker are managed and uploaded by the BallPicker team via the admin panel. If you believe any image infringes your rights, please contact us at the address below.</p>

<h2>Changes to this policy</h2>
<p>If we make significant changes we will update the date at the top of this page and, where appropriate, notify you in the app.</p>

<h2>Contact</h2>
<p>Questions about this policy or your data? Email us at <a class="inline-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
