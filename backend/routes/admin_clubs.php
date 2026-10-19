<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — central admin (/admin/…)
|--------------------------------------------------------------------------
| Required from routes/web.php INSIDE the existing `admin` prefix/middleware
| group, so every route here is already /admin/… + session admin auth.
| Deliberately NOT behind feature:clubs: platform admins can prepare clubs
| before the app update ships.
|
| Every controller action still authorizes through the policies:
| $this->authorize('admin.manage', $club) or Gate::authorize('admin.manage')
| for indexes (Gate::before grants platform admins in the admin area).
| Domain failures throw ClubDomainException → back() with the `error` flash.
| Route names: admin.clubs.*, admin.club-competitions.*, admin.club-photos.*,
| admin.club-audit.*.
*/

use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Admin clubs (B2 — Central Admin): index, create, detail tabs, edit,
// settings, suspend/archive/reactivate, delete-if-empty, members, invite
// codes + QR print, packs preset, audit tab, stats.
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// Admin competitions (C1 — Competitions): per-club editor + publish/cancel/
// void/extend/rebuild, attempt void, cross-club list admin.club-competitions.*.
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// Admin photos (D1 — Photos): global review queue admin.club-photos.*,
// upload on behalf of a club, assign / prepare / convert / reject.
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// Global audit log (B2): admin.club-audit.index.
// -------------------------------------------------------------------------
