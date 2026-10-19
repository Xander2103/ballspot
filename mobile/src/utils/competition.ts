/**
 * Competition presentation + play-track mapping (pure, unit tested).
 *
 *  - competitionPackAction: the per-pack button (AT-MOB-03), derived only from
 *    the server's `state` (`can_start` / `blocked_reason` / `active_attempt_id`)
 *    and the competition phase. The app never decides eligibility itself; every
 *    start is re-checked by the backend.
 *  - countdown / window formatting in the competition's own time zone.
 *  - rules description for the three replay modes + the normal-play policy.
 *  - mapping of the competition attempt / guess payloads onto the pack shapes
 *    the shared guess / result screens already render.
 */
import { translate, type TranslateParams } from '../i18n/core';
import type { LanguageCode } from './language';
import { dateLocaleTag } from './clubDisplay';
import type {
  CompetitionAttempt, CompetitionAttemptResponse, CompetitionBlockedReason, CompetitionPackState, CompetitionPhase,
  CompetitionRules,
} from '../types/club';
import type { PackAttemptState, PackGuessResult, PackStartResponse } from '../types/pack';

// --- Button state (AT-MOB-03) ------------------------------------------------

export type CompetitionPackAction =
  | { kind: 'continue'; attemptId: number }
  | { kind: 'start'; attemptNumber: number; /** "Start" (one attempt) vs "Start attempt N". */ numbered: boolean }
  | { kind: 'blocked'; reasonKey: string }
  /** Upcoming / finished competitions: packs are listed without play buttons. */
  | { kind: 'none' };

const BLOCKED_KEYS: Record<CompetitionBlockedReason, string> = {
  not_started: 'clubs.competition.blocked.not_started',
  ended: 'clubs.competition.blocked.ended',
  limit_reached: 'clubs.competition.blocked.limit_reached',
  already_played: 'clubs.competition.blocked.already_played',
  attempt_in_progress: 'clubs.competition.blocked.attempt_in_progress',
  club_unavailable: 'clubs.competition.blocked.club_unavailable',
};

export function blockedReasonKey(reason: CompetitionBlockedReason | string | null | undefined): string {
  return reason && reason in BLOCKED_KEYS ? BLOCKED_KEYS[reason as CompetitionBlockedReason] : 'clubs.competition.blocked.unavailable';
}

export function competitionPackAction(
  state: CompetitionPackState,
  phase: CompetitionPhase,
  rules: Pick<CompetitionRules, 'max_attempts' | 'replay_mode'>,
): CompetitionPackAction {
  if (phase !== 'active') return { kind: 'none' };
  // An attempt in progress always wins: the player finishes it (even if the
  // server also reports attempt_in_progress as the blocked reason for a new one).
  if (state.active_attempt_id) return { kind: 'continue', attemptId: state.active_attempt_id };
  if (state.can_start && state.next_attempt_number && state.next_attempt_number > 0) {
    return {
      kind: 'start',
      attemptNumber: state.next_attempt_number,
      numbered: rules.replay_mode === 'multiple_attempts' && rules.max_attempts > 1,
    };
  }
  return { kind: 'blocked', reasonKey: blockedReasonKey(state.blocked_reason) };
}

/** "Attempts 2/3" (multiple mode) or null when a pack allows a single attempt. */
export function attemptsLabel(state: CompetitionPackState, rules: Pick<CompetitionRules, 'max_attempts' | 'replay_mode'>, locale?: LanguageCode): string | null {
  if (rules.replay_mode !== 'multiple_attempts' || rules.max_attempts <= 1) return null;
  return translate('clubs.competition.attempts', { used: state.attempts_used, max: rules.max_attempts }, locale);
}

/** "Counted 840 · best 840" — only the parts the server reports. */
export function packScoreParts(state: CompetitionPackState, rules: Pick<CompetitionRules, 'score_rule' | 'replay_mode'>, locale?: LanguageCode): string[] {
  const parts: string[] = [];
  if (typeof state.counted_score === 'number') parts.push(translate('clubs.competition.counted', { score: state.counted_score.toLocaleString() }, locale));
  if (rules.replay_mode === 'multiple_attempts' && typeof state.best_score === 'number' && state.best_score !== state.counted_score) {
    parts.push(translate('clubs.competition.best', { score: state.best_score.toLocaleString() }, locale));
  }
  return parts;
}

// --- Phase / countdown -------------------------------------------------------

export type CompetitionGroup = 'upcoming' | 'active' | 'completed' | 'closed';

/** upcoming · active · completed (ended + finalized) · closed (cancelled / voided). */
export function competitionGroup(phase: CompetitionPhase): CompetitionGroup {
  if (phase === 'upcoming' || phase === 'active') return phase;
  if (phase === 'ended' || phase === 'finalized') return 'completed';
  return 'closed';
}

export interface Countdown {
  days: number;
  hours: number;
  minutes: number;
  /** True once the target is reached (or the date is unreadable). */
  done: boolean;
}

export function countdownTo(targetIso: string | null | undefined, nowMs: number): Countdown {
  const target = targetIso ? Date.parse(targetIso) : Number.NaN;
  if (!Number.isFinite(target) || target <= nowMs) return { days: 0, hours: 0, minutes: 0, done: true };
  // Round UP to the minute: "1m" until the very end, never "0m" while running.
  const totalMinutes = Math.ceil((target - nowMs) / 60000);
  return {
    days: Math.floor(totalMinutes / 1440),
    hours: Math.floor((totalMinutes % 1440) / 60),
    minutes: totalMinutes % 60,
    done: false,
  };
}

/** "3d 4h" · "4h 12m" · "12m" (two largest units). */
export function formatCountdown(c: Countdown, locale?: LanguageCode): string {
  const d = translate('clubs.competition.units.d', { n: c.days }, locale);
  const h = translate('clubs.competition.units.h', { n: c.hours }, locale);
  const m = translate('clubs.competition.units.m', { n: c.minutes }, locale);
  if (c.days > 0) return c.hours > 0 ? `${d} ${h}` : d;
  if (c.hours > 0) return c.minutes > 0 ? `${h} ${m}` : h;
  return m;
}

/** The status line under the window: "Starts in 2d 3h" / "Ends in 4h 10m" / "Ended" / "Cancelled". */
export function competitionStatusLine(
  c: { phase: CompetitionPhase; starts_at: string; ends_at: string },
  nowMs: number,
  locale?: LanguageCode,
): string {
  if (c.phase === 'cancelled') return translate('clubs.competition.status.cancelled', undefined, locale);
  if (c.phase === 'voided') return translate('clubs.competition.status.voided', undefined, locale);
  if (c.phase === 'finalized') return translate('clubs.competition.status.finalized', undefined, locale);
  if (c.phase === 'upcoming') {
    const cd = countdownTo(c.starts_at, nowMs);
    return cd.done
      ? translate('clubs.competition.status.startingNow', undefined, locale)
      : translate('clubs.competition.status.startsIn', { time: formatCountdown(cd, locale) }, locale);
  }
  if (c.phase === 'active') {
    const cd = countdownTo(c.ends_at, nowMs);
    return cd.done
      ? translate('clubs.competition.status.ended', undefined, locale)
      : translate('clubs.competition.status.endsIn', { time: formatCountdown(cd, locale) }, locale);
  }
  return translate('clubs.competition.status.ended', undefined, locale);
}

/** "Brussels" from "Europe/Brussels", "Buenos Aires" from "America/Argentina/Buenos_Aires". */
export function timeZoneLabel(timeZone: string | null | undefined): string {
  if (!timeZone) return '';
  const last = timeZone.split('/').pop() ?? timeZone;
  return last.replace(/_/g, ' ');
}

/** "1 Nov, 10:00" rendered in `timeZone` (falls back to the device zone if the runtime lacks it). */
export function formatInZone(iso: string | null | undefined, timeZone: string | null | undefined, locale?: LanguageCode | string): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false };
  try {
    return date.toLocaleString(dateLocaleTag(locale), timeZone ? { ...options, timeZone } : options);
  } catch {
    try {
      return date.toLocaleString(dateLocaleTag(locale), options);
    } catch {
      return date.toISOString().slice(0, 16).replace('T', ' ');
    }
  }
}

/** "1 Nov, 10:00 – 30 Nov, 22:00 (Brussels)". */
export function competitionWindow(
  c: { starts_at: string; ends_at: string; timezone: string },
  locale?: LanguageCode | string,
): string {
  const range = `${formatInZone(c.starts_at, c.timezone, locale)} – ${formatInZone(c.ends_at, c.timezone, locale)}`;
  const zone = timeZoneLabel(c.timezone);
  return zone ? `${range} (${zone})` : range;
}

// --- Rules -------------------------------------------------------------------

export interface RuleLine {
  key: string;
  params?: TranslateParams;
}

/**
 * Human-readable rules, most important first:
 *  - replay mode (+ score rule for multiple attempts)
 *  - normal-play policy (blocked → packs are only playable here)
 *  - the score window
 */
export function describeRules(rules: CompetitionRules): RuleLine[] {
  const lines: RuleLine[] = [];
  if (rules.replay_mode === 'no_replay') {
    lines.push({ key: 'clubs.competition.rules.noReplay' });
  } else if (rules.replay_mode === 'multiple_attempts' && rules.max_attempts > 1) {
    lines.push(rules.score_rule === 'best'
      ? { key: 'clubs.competition.rules.bestOf', params: { max: rules.max_attempts } }
      : { key: 'clubs.competition.rules.firstOf', params: { max: rules.max_attempts } });
  } else {
    lines.push({ key: 'clubs.competition.rules.oneAttempt' });
  }
  lines.push(rules.normal_play_policy === 'blocked'
    ? { key: 'clubs.competition.rules.normalBlocked' }
    : { key: 'clubs.competition.rules.normalAllowed' });
  lines.push({ key: 'clubs.competition.rules.window' });
  return lines;
}

export function describeRulesText(rules: CompetitionRules, locale?: LanguageCode): string[] {
  return describeRules(rules).map((l) => translate(l.key, l.params, locale));
}

// --- Mapping onto the pack play shapes --------------------------------------

/** Competition attempt → the pack attempt shape (expired/voided read as "abandoned": nothing left to play). */
export function toPackAttemptState(a: CompetitionAttempt): PackAttemptState {
  return {
    id: a.id,
    status: a.status === 'active' ? 'active' : a.status === 'completed' ? 'completed' : 'abandoned',
    current_index: a.current_index,
    total_score: a.total_score,
    completed_count: a.completed_count,
    total_challenges: a.total_challenges,
    started_at: a.started_at,
    completed_at: a.completed_at,
  };
}

export function toPackStartResponse(res: CompetitionAttemptResponse): PackStartResponse {
  return { attempt: toPackAttemptState(res.attempt), challenge: res.challenge ?? null, completion: null };
}

type CompetitionGuessResponse = Omit<PackGuessResult, 'progress'> & { progress: CompetitionAttempt | PackAttemptState; replayed?: boolean };

function isCompetitionAttempt(p: CompetitionAttempt | PackAttemptState): p is CompetitionAttempt {
  return typeof (p as CompetitionAttempt).is_rewarded_run === 'boolean' || typeof (p as CompetitionAttempt).attempt_number === 'number';
}

/**
 * Competition guess → pack guess result. `replayed` (a retry of an accepted
 * guess) maps onto the pack screens' `already_completed` note; the reward flag
 * comes from the attempt so the complete view can explain "no XP".
 */
export function toPackGuessResult(res: CompetitionGuessResponse): PackGuessResult {
  const progress = res.progress;
  const competitionAttempt = isCompetitionAttempt(progress) ? progress : null;
  return {
    ...res,
    progress: competitionAttempt ? toPackAttemptState(competitionAttempt) : (progress as PackAttemptState),
    already_completed: res.replayed === true || res.already_completed === true,
    completion: null,
    competition: res.competition ?? null,
    is_rewarded_run: competitionAttempt ? competitionAttempt.is_rewarded_run : (res.is_rewarded_run ?? null),
  };
}

/** Did this run earn global XP? Unknown → infer from the XP the server actually granted. */
export function isRewardedRun(result: Pick<PackGuessResult, 'is_rewarded_run' | 'completion_xp' | 'rank_progress'> | null | undefined): boolean {
  if (!result) return true;
  if (typeof result.is_rewarded_run === 'boolean') return result.is_rewarded_run;
  return (result.completion_xp ?? 0) > 0 || (result.rank_progress?.xp_gained ?? 0) > 0;
}
