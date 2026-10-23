# Profile 2.0 — compact player profile + Settings screen (2026-10-22)

## What changed

The Profile tab is no longer a long settings page. It is a compact player
profile; everything that is a *setting* moved to a dedicated **Settings**
screen behind the gear in the Profile header. No behaviour was removed: every
call, confirmation, validation and safeguard of the old screen runs unchanged
from its new home.

### Profile tab (`screens/ProfileScreen.tsx`)

| Section | Component | Notes |
| --- | --- | --- |
| Header | `components/profile/ProfileHeader.tsx` | 72pt avatar with a camera badge (tap = change photo, same picker/permission/upload flow), name, `@handle`, rank pill (gold level box + rank name + "Level N"). |
| XP card | `components/profile/XpProgressCard.tsx` | Total XP, next rank, progress bar, "N XP to Rank". Numbers come from `utils/profileModel.xpProgress`, which is the old `RankCard` arithmetic verbatim (server pct clamped to 0..100, full bar at max rank). Border carries the rank tier (`theme/rankVisuals`). Tap opens the rank ladder. |
| At-a-glance tiles | `components/profile/StatTiles.tsx` | Streak, total score, best daily. Each tile opens the matching stats segment. |
| Achievements & stats | `components/ListRow.tsx` (`ListGroup` + `ListRow`) | Trophy Room → `TrophyRoom`; Statistics → `ProfileStats` (overall); Daily Challenge (subtitle = 🔥 streak) → `ProfileStats` (daily); History (value = "N finished") → `ProfileStats` (history); View all ranks → `RankOverview`. |
| Share / Rate | `components/profile/ActionCard.tsx` | Same `growth.shareApp()` / `growth.rateApp()` calls, same bounded XP promise (`availableShareRewards`), same messages. |

Failure handling is unchanged: `/me`, `/profile/stats` and the finishes load
with `allSettled`; a failed `/me` shows the Retry/Logout empty state, failed
stats show a compact retry card, a failed history is reported on the row.

### "Your stats" (`screens/ProfileStatsScreen.tsx`, route `ProfileStats`)

Segmented control (`components/SegmentedControl.tsx`): **Overall** (the old
Stats list), **Daily** (streak banner + the old Daily challenge stats list),
**History** (the full `ProfileHistoryCard`, no longer capped at 10). Data is
re-fetched on open; each segment has its own retry/empty state.

### Settings (`screens/SettingsScreen.tsx`, route `Settings`)

Groups: Preferences (preferred sport → `SportSelection`), App theme
(horizontal theme chips, active description below), Language
(`LanguagePicker`, optimistic `setLocale` with revert), Notifications
(`NotificationSettingsCard flat`), Account & security (2FA switch, still gated
by `login_two_factor_opt_in` and only rendered when allowed or already on;
Log out row), About (Privacy Policy / Terms / Support, external links),
Delete account (small danger link → destructive `ConfirmModal` with in-flight
lock, error text, "Account deleted" alert and the 4 s fallback), version and
credits.

### Navigation

- `app/AppNavigator.tsx`: `Settings: undefined`, `ProfileStats: { section? }`.
- `app/MainTabs.tsx`: Profile tab `headerRight` gear (`HeaderIconButton
  icon="settings"`, testID `profile-settings`) → parent stack `Settings`.

### Icons

`components/LineIcon.tsx` gained settings, camera, chevronRight, trophy,
stats, flame, medal, share, star, palette, globe, bell, shield, logout,
external, trash, info, help — same 24-grid round-stroke style as the tab and
sport icons; no icon font added.

### i18n

New keys (all five languages): `nav.titles.settings`, `nav.titles.playerStats`,
`profile.header.level`, `profile.xp.*`, `profile.tiles.bestDaily`,
`profile.nav.*`, `profile.statsScreen.*`, `profile.settings.*`. Removed (no
longer referenced anywhere): `profile.screen.{yourSport, changeSport,
themeActive, stats, dailyStats}`, `profile.account.summary`. Other locales
are typed as `Translations`, so `tsc --noEmit` enforces completeness.

## Tests

- `utils/__tests__/profileModel.test.ts` — section parsing, XP arithmetic
  (pass-through, clamping, max rank, null), tiles, history summary.
- `utils/__tests__/profileRedesign.test.ts` — source pins: routes registered,
  gear → Settings, every achievement link, no accordions, photo flow, Share /
  Rate calls, XP card uses the shared model, Settings keeps sport / theme /
  language / notifications / 2FA / logout / legal / deletion / version, delete
  safeguards, optimistic reverts, logout order.
- Updated pins that followed the moved code: `session.test.ts`,
  `urls.test.ts`, `launchTwoFactor.test.ts`, `i18n/__tests__/screens.test.ts`
  (new screens/components added to the launch-critical list).

Results on this change: `tsc --noEmit` clean; Jest 30 suites / 354 tests
pass (was 28 / 330). Backend untouched; `DailyNextDailyTest` +
`ProfileStatsTest` (11 tests) pass in the worktree.

## Visual QA

See `/Users/FOTOTHEEK/Documents/ballspot-profile-qa` (outside the repo):
`before/` (old Profile), `shots/` (new Profile, stats, Settings, delete
confirm, sport picker, share sheet; all five themes; all five languages),
Maestro flows in `maestro/`, `run.sh`.

Maestro 2.11 against the local master build (debug app on the "BP Home"
simulators, Metro :8082, throwaway backend :8091 / `polish-qa.sqlite`):

| Flow | iPhone 17 (iOS 26.5) | iPhone SE 3 (iOS 26.5) |
| --- | --- | --- |
| `profile-tour` — Profile top/scrolled, XP card → ranks, Trophy Room, Statistics (3 segments), Daily / History deep links, streak tile, View all ranks, gear → Settings (3 screens), Delete account confirm → cancel, Preferred sport → picker, Share → native sheet | pass (p01–p17) | pass (p01–p17) |
| `profile-themes` — Profile + Settings in Classic, Tournament Blue, Sunset Orange, High Contrast, Pitch Green | pass (10 shots) | n/a (theme is a JS-level token swap; covered on 17) |
| `profile-languages` — Settings, Profile and stats in nl, fr, de, es, en | pass (20 shots) | n/a |
| `final-tour` — Home with countdown, Packs, Tournaments (+ Home button, Join), Friends tabs / sent / requests / discover / QR, Profile tab | pass (f01–f09) | pass via `home-tour-nocountdown` (f01–f09; by then the scheduled daily had become today's playable daily, so no countdown is expected) |

Static checks: `tsc --noEmit` clean, Jest 30 suites / 354 tests, `git diff --check` clean.
Not automated: Rate BallPicker (opens the store page and leaves the app; the
call path is pinned by `profileRedesign.test.ts` and unchanged from v1.9.12),
the Log out row (same `logoutLocally` call as before, pinned by tests) and the
destructive confirm itself (never confirmed on the QA account).

## Known limitations

- BallPicker has **no light theme**: the five palettes are all dark (High
  Contrast is the brightest). "Dark/light" was therefore verified across all
  five themes, not against a separate light mode.
- `ConfirmModal` still uses the static `colors` palette (classic navy) rather
  than the active theme — pre-existing, unchanged here.
- `RankCard` and `CollapsibleSection` are no longer used by Profile; they are
  kept (unused component / `formatBadgeCount` still imported) to avoid churn
  on the Clubs branch.
