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

## Explicit non-goals (current and this sprint)

- ❌ Real-money gambling or betting
- ❌ Payments, in-app purchases, or App Store purchase logic
- ❌ Real prize claiming or fulfilment
- ❌ XP marketplace / buying, selling, or redeeming XP for value
- ❌ Ads
