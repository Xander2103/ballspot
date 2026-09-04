# Prizes & Trophy Room

Status: **Trophy Room (virtual) is LIVE in v1.6. Real prizes are NOT implemented
and are out of scope until a full legal/compliance review is done.**

## What ships today (v1.6)

- **Virtual-only Trophy Room.** Users earn virtual badges/medals for milestones
  (first daily, streaks, near-perfect guesses, daily champion, top 10%,
  tournament winner, sport-specific, etc.). See `badges` / `user_badges`.
- Badges carry no monetary value and cannot be redeemed for anything.
- Awarding is idempotent (a badge is earned once) and happens after guess/result
  calculation via `BadgeService`.

## XP & rank progression (v1.7.3) — still virtual, still no prizes

- **XP is now ledger-backed.** Every XP award is recorded as an append-only row in `xp_events`
  (the new source of truth), and `PlayerRankService` derives a player's rank/level from the ledger
  sum (with a lifetime-score fallback until `ballspot:backfill-xp` runs).
- **XP can now come from multiple sources:** guess submissions (daily + tournament), badge unlocks
  (rarity bonus), and daily-streak milestones. **Tournament-win XP is config-ready but not awarded
  yet** (a future source, once robust tournament completion/winner logic exists).
- **XP and rank carry no monetary value.** Like badges/trophies, XP and rank are **cosmetic**
  progression only: XP cannot be bought, sold, earned for money, staked, or redeemed for anything.
  There is **no XP marketplace, no currency, no payments, and no real-money rewards.** Rank-up
  moments are a visual celebration, nothing more.

## Badge expansion (v1.7.4) — still virtual, still no prizes

- The catalogue grew to **19 badges** with a canonical taxonomy layered on the original set.
  New badges: **Perfect Picker** (🎯 legendary — a perfect 100 guess), **Almost Perfect**
  (🔥 epic — score ≥ 95), **Daily Debut** (🌅), **On a Roll** / **Week Warrior** /
  **Monthly Machine** (streak 3/7/30), **Top 10%** (🥇), **Multi-Sport Starter** (🌍) and an
  updated **Tournament Winner** (🏆 epic).
- Perfect / almost-perfect thresholds are centralized in `config('ballspot.scoring')` and read
  through `ScoreService::isPerfectScore()` / `isAlmostPerfect()` — never scattered in controllers.
- **Badge XP uses the existing XP ledger** (`xp_events`, source `badge_unlock`), keyed by rarity
  (common 100 / rare 250 / epic 500 / legendary 1000), awarded exactly once per badge per user.
- **Auto-awarded now:** Perfect Picker, Almost Perfect, Daily Debut, streak 3/7/30 (streak data
  permitting), Top 10% daily, Multi-Sport Starter.
- **Seeded but not fully automatic yet:** `tournament_winner` (needs robust tournament winner
  logic — `BadgeService::evaluateTournamentWin()` exists and is idempotent, but nothing calls it
  on completion in this sprint). Legacy `weekly_top_10` remains snapshot-based.
- **Legacy overlap:** some legacy codes still fire alongside the new canonical codes (e.g.
  `perfect_guess`, `first_daily`, `seven_day_streak`) so already-earned badges stay valid. A
  future sprint may consolidate these.
- **Result screens** show a "Badge unlocked!" card; a legendary unlock (Perfect Picker) gets a
  distinct "Legendary badge unlocked" treatment. Rank-up and badge cards render together cleanly.
- Everything here is **cosmetic** — no monetary value, no redemption, no payments.

## Tournament completion & finishes (v1.7.7) — still virtual, still no prizes

- **When a tournament completes:** it is `active` and every member has played every round. The
  finishing round-guess triggers an **atomic, once-only** completion (`active → completed`).
- **Recognition:** placement 1 → `tournament_winner`; placements 1–3 → `podium_finish` (🥉 rare),
  awarded only when the tournament has **at least 3 players** (v1.9.3 — in a 2-player field
  everybody is "top 3"). Placement XP via the **existing XP ledger**
  (`source_type: tournament_win`, deduped per user per tournament): **1st +1000, 2nd +500, 3rd +250**.
- **Skill trophies (v1.9.3, awarded on the same once-only completion):**
  - `sharpshooter` (🏹 rare) — the single closest guess of the tournament by stored `distance`
    (ties: earliest submission, then user id; falls back to highest single score if a distance
    were ever missing — the schema currently guarantees one).
  - `most_consistent` (📊 rare) — best average score; only players with guesses on at least
    half the rounds (minimum 2) are eligible, at least 2 must be eligible, and single-round
    tournaments are skipped. When fairness can't be established, the award is skipped.
  - Both respect the `min_players_for_rewards` gate (solo tournaments award nothing) and the
    once-per-user `user_badges` uniqueness — repeat wins show in `tournament_finishes` history.
- **Tie rule:** total score desc → earliest completion (last-guess time) asc → user id asc.
- **Final standings** are stored in `tournament_finishes` (one row per member) and surfaced in the
  Trophy Room "Tournament trophies" section via `GET /api/me/tournament-finishes`.
- **Still virtual:** placements, badges and XP carry no monetary value and cannot be redeemed. No
  prizes, currency, or payments. Cancelled tournaments award nothing.
- **Known limitation:** a member who never plays keeps a tournament open (owner can still cancel);
  a scheduled time-based completion sweep is a future improvement.

## Hall of Fame (future idea)

A **Monthly Hall of Fame** would immortalize each month's top players:

- End-of-month snapshot of the monthly daily leaderboard (top N by total score).
- Stored as an immutable record (`hall_of_fame_entries`: month, user, rank, score).
- Displayed as a virtual honor — **still no real prize attached.**
- A monthly leaderboard query can be added cheaply (mirror the existing weekly
  query with a month range). Not built in v1.6 to avoid scope creep.

## Real prizes — hard requirements before ANY are offered

Real prizes are a significant legal/compliance undertaking. They must **not** be
added casually. Before a single real prize is offered:

1. **No gambling, ever.** No wagering, no staking, no purchase-to-enter that
   resembles a lottery. This is a skill-based guessing game and must stay one.
2. **No purchase required.** If a real-prize competition is ever run, entry must
   be free (AMOE — alternative method of entry) to avoid lottery classification.
3. **Legal review per jurisdiction.** Sweepstakes/skill-contest law varies by
   country and (in the US) by state. Requires official rules, eligibility, and
   disclosures reviewed by counsel.
4. **Fraud & cheat prevention first.** Before prizes have value, we need:
   anti-automation, duplicate-account detection, server-authoritative scoring
   (already the case), rate limiting, and anomaly review. A prize is an incentive
   to cheat — the integrity work comes first.
5. **Age & country eligibility.** Enforce minimum age and exclude ineligible
   regions. Collect only what is legally necessary; document retention.
6. **Tax & fulfilment.** Winner verification, tax forms where required, and a
   fulfilment process for physical goods.

## Sponsor prize ideas (only under the framework above)

- Gift cards
- Team shirts / merch
- Match tickets
- Branded merchandise

These remain **ideas only**. None are implemented, promised, or displayed as
available in the app.

## Pack trophies (v1.7.9)

Challenge packs are playable and completions are recorded as **virtual** Trophy Room trophies
(no prizes/money). The Trophy Room "Pack trophies" section lists completed packs (name, total
score, challenge count, perfect indicator, date) via `GET /api/me/pack-completions`; empty state
"No completed packs yet." Completion awards `+250` XP (config `ballspot.xp.pack_completion`) and
badges: **Pack Finisher** (first pack), **Perfect Pack** (all-perfect, legendary), **Pack
Master** (10 packs). No fake completions are ever shown; nothing is purchasable.

## Competition trophies (v1.8.0) — LIVE, still virtual, still no prizes

Monthly (or weekly) competitions can now be **closed** and their top 3 awarded real —
but strictly **virtual** — recognition:

- **Close command:** `php artisan ballspot:close-competition` closes the most recently
  **completed** period (in July, the monthly close targets June — the current open period is
  never closed by default; `--force` exists for deliberate ops use only). `--dry-run` previews
  without writing anything; `--period=YYYY-MM` (monthly) / `--period=YYYY-WW` (weekly) +
  `--type=monthly|weekly` select a specific window. Re-running a close is **idempotent** —
  finishes, XP and badges are never duplicated.
- **Standings come from the same logic as the live leaderboard** (`CompetitionStandingsService`,
  shared with `GET /api/daily/leaderboard/weekly`). Tie rule: total score desc → earliest
  last-qualifying guess asc → user id asc (deterministic).
- **Storage:** top-3 placements are stored in `competition_finishes` (period type/label/window,
  placement, total score, total players, XP awarded, awarded_at). Only **real** placements are
  written — 1 eligible player means a single 1st place, never fake 2nd/3rd; no players means a
  clean "no eligible players" exit with no records.
- **XP** via the existing ledger (`source_type: competition_finish`, deduped per finish):
  **1st +2000, 2nd +1000, 3rd +500** (`config('ballspot.xp.competition_finish')`). It appears in
  Recent XP and counts toward rank like any other XP.
- **Badges (monthly closes only):** placement 1 → **Monthly Winner** (🏆 legendary) +
  **Monthly Podium** (🥉 epic); placements 2–3 → Monthly Podium; **Monthly Top 10** (🥇 rare)
  goes to the top 10% when the field has ≥ 10 players (mirroring the live top-10 guard). Badge
  unlock XP uses the standard rarity bonuses. Weekly closes store finishes + XP but no monthly_*
  badges.
- **Trophy Room:** the "Competition trophies" section now lists real closed-period finishes via
  `GET /api/me/competition-finishes` (placement medal, period label, players, score, XP). Empty
  state: "No competition trophies yet." **Only closed periods appear — the live leaderboard
  position is never displayed as a trophy.**
- **Historical records survive account anonymization** (deletion anonymizes the user row in
  place; the finish keeps pointing at it, and the FK nulls on any hard delete).
- **Known limitations:** no automated scheduler runs the close (manual/ops CLI action);
  `--announce` saves a **draft** admin announcement only — nothing is ever auto-sent.

## Explicit non-goals (current and this sprint)

- ❌ Real-money gambling or betting
- ❌ Payments, in-app purchases, or App Store purchase logic
- ❌ Paid challenge packs, a shop, or pack purchases
- ❌ Real prize claiming or fulfilment
- ❌ XP marketplace / buying, selling, or redeeming XP for value
- ❌ Ads

## Pack replay & score-based trophies (v1.9.5)

**Replay is disabled.** A completed pack cannot be started again (`POST
/packs/{slug}/start` → 409): the player already knows every ball position, so a
second run would be worthless as a challenge and exploitable for XP. The app
shows "✓ Completed" + "View results" (the completion overview) instead of "Play
again". Old in-flight replay attempts from before this rule stay playable but
pay no XP (`PackPlayService::hasCompletedBefore`).

**TODO (not implemented for launch — deliberately):** score-based pack tiers.
Plan, when picked up:
- Keep the existing per-pack completion trophy (`completion_badge_id`) as the
  "Bronze" tier — complete the pack.
- Silver: `completion.average_pct >= 70`; Gold: `>= 85` (thresholds in
  `config/ballspot.php` under `packs.trophy_tiers`, not hard-coded).
- Either two extra badge rows per pack (`pack_{id}_silver|gold`, seeded by the
  admin pack form like the completion badge) or one badge with a `tier` pivot
  column — decide with the Trophy Room design; the pivot approach avoids a
  badge-count explosion in `/api/badges`.
- Evaluate in `BadgeService::evaluatePackCompletion` from the same
  `completionSummary()` the API already returns, so app and server agree on the
  percentage. Idempotent `award()` keeps it race-safe.
- Because replay is disabled, tiers are decided by the first (only) run — no
  "grind to gold" loophole. Document that in the Trophy Room copy.
- Risk that pushed it out of the launch sprint: a new badge taxonomy touches
  `BadgeSeeder` counts in ~10 test files and the Trophy Room UI; not worth
  coupling to the completion-bug fix.
