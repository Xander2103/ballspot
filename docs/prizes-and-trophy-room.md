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
- **Recognition:** placement 1 → `tournament_winner` + `podium_finish` badges; placements 1–3 →
  `podium_finish` (new 🥉 rare badge). Placement XP via the **existing XP ledger**
  (`source_type: tournament_win`, deduped per user per tournament): **1st +1000, 2nd +500, 3rd +250**.
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

## Competition trophies (v1.7.8 prep)

The Trophy Room now shows a **"Competition trophies"** section with an honest empty state
("Top finishes will appear here when monthly competitions end."). This is **preparation only**:

- The competition period is now configurable (weekly/monthly, monthly default) and drives the
  leaderboard + top-finishers badge (`config('ballspot.competition')`).
- **No monthly top-3 finish trophies are awarded yet** — the future architecture mirrors the
  existing tournament `tournament_finishes` table (a `competition_finishes`-style record written
  when a period closes). Pack-completion badges are likewise future.
- Consistent with the rules below: **no fake trophies, no fake wins** — the section stays empty
  until real period-close awards exist. All future trophies remain **virtual only**.

## Explicit non-goals (current and this sprint)

- ❌ Real-money gambling or betting
- ❌ Payments, in-app purchases, or App Store purchase logic
- ❌ Paid challenge packs, a shop, or pack purchases
- ❌ Real prize claiming or fulfilment
- ❌ XP marketplace / buying, selling, or redeeming XP for value
- ❌ Ads
