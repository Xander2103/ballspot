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
- ❌ Ads
