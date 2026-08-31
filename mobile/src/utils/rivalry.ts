import { LeaderboardEntry } from '../types/guess';

/**
 * Pure rivalry-status helpers for the tournament detail screen. Both return
 * null when the data cannot support a safe statement — callers hide the text
 * instead of crashing (old tournaments may have partial data).
 */

const DAY_MS = 24 * 60 * 60 * 1000;

function points(n: number): string {
  return `${n} point${n === 1 ? '' : 's'}`;
}

function displayName(e: LeaderboardEntry): string {
  return e.name || e.username || 'A player';
}

export function rivalryLine(entries: LeaderboardEntry[] | null | undefined): string | null {
  if (!Array.isArray(entries) || entries.length < 2) return null;

  const sorted = [...entries].sort((a, b) => (b.total_score ?? 0) - (a.total_score ?? 0));
  const leader = sorted[0];
  const second = sorted[1];
  if (typeof leader?.total_score !== 'number' || typeof second?.total_score !== 'number') return null;

  const me = sorted.find(e => e.is_current_user);

  if (me && me.user_id === leader.user_id) {
    const margin = leader.total_score - second.total_score;
    return margin === 0 ? "It's currently tied." : `You are leading by ${points(margin)}`;
  }
  if (me) {
    if (typeof me.total_score !== 'number') return null;
    const deficit = leader.total_score - me.total_score;
    return deficit === 0 ? "It's currently tied." : `You are ${points(deficit)} behind ${displayName(leader)}`;
  }
  const margin = leader.total_score - second.total_score;
  return margin === 0 ? "It's currently tied." : `${displayName(leader)} leads by ${points(margin)}`;
}

export function daysLeftLabel(endsAt: string | null | undefined, now: Date = new Date()): string | null {
  if (!endsAt) return null;
  const end = new Date(endsAt);
  if (Number.isNaN(end.getTime())) return null;
  const days = Math.max(0, Math.ceil((end.getTime() - now.getTime()) / DAY_MS));
  return `${days} day${days === 1 ? '' : 's'} left`;
}
