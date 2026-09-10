import { LeaderboardEntry } from '../types/guess';
import { translate as t } from '../i18n/core';

/**
 * Pure rivalry-status helpers for the tournament detail screen. Both return
 * null when the data cannot support a safe statement — callers hide the text
 * instead of crashing (old tournaments may have partial data).
 *
 * Copy lives under `tournaments.rivalry.*`; the i18n core is pure TS so this
 * stays usable from the plain ts-jest runner.
 */

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * total_score is SUM(guesses.score) from the API; MySQL returns that DECIMAL
 * aggregate as a string, so coerce before doing math. Null when not numeric.
 */
function toScore(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function points(n: number): string {
  return t('tournaments.rivalry.points', { count: n });
}

function displayName(e: LeaderboardEntry): string {
  return e.name || e.username || t('tournaments.rivalry.aPlayer');
}

export function rivalryLine(entries: LeaderboardEntry[] | null | undefined): string | null {
  if (!Array.isArray(entries) || entries.length < 2) return null;

  const sorted = [...entries].sort(
    (a, b) => (toScore(b.total_score) ?? 0) - (toScore(a.total_score) ?? 0),
  );
  const leader = sorted[0];
  const leaderScore = toScore(leader?.total_score);
  const secondScore = toScore(sorted[1]?.total_score);
  if (leaderScore === null || secondScore === null) return null;

  const me = sorted.find(e => e.is_current_user);

  if (me && me.user_id === leader.user_id) {
    const margin = leaderScore - secondScore;
    return margin === 0
      ? t('tournaments.rivalry.tied')
      : t('tournaments.rivalry.leading', { points: points(margin) });
  }
  if (me) {
    const myScore = toScore(me.total_score);
    if (myScore === null) return null;
    const deficit = leaderScore - myScore;
    return deficit === 0
      ? t('tournaments.rivalry.tied')
      : t('tournaments.rivalry.behind', { points: points(deficit), name: displayName(leader) });
  }
  const margin = leaderScore - secondScore;
  return margin === 0
    ? t('tournaments.rivalry.tied')
    : t('tournaments.rivalry.leaderLeads', { name: displayName(leader), points: points(margin) });
}

export function daysLeftLabel(endsAt: string | null | undefined, now: Date = new Date()): string | null {
  if (!endsAt) return null;
  const end = new Date(endsAt);
  if (Number.isNaN(end.getTime())) return null;
  const days = Math.max(0, Math.ceil((end.getTime() - now.getTime()) / DAY_MS));
  return t('tournaments.rivalry.daysLeft', { count: days });
}
