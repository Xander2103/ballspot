/** AT-MOB-03 competition button states + countdown / window / rules / play-track mapping. */
import { translate } from '../../i18n/core';
import {
  attemptsLabel, blockedReasonKey, competitionGroup, competitionPackAction, competitionStatusLine, competitionWindow,
  countdownTo, describeRules, describeRulesText, formatCountdown, isRewardedRun, packScoreParts, timeZoneLabel,
  toPackAttemptState, toPackGuessResult, toPackStartResponse,
} from '../competition';
import type { CompetitionAttempt, CompetitionPackState, CompetitionRules } from '../../types/club';
import type { PackGuessResult } from '../../types/pack';

const state = (over: Partial<CompetitionPackState> = {}): CompetitionPackState => ({
  attempts_used: 0, attempts_remaining: 1, next_attempt_number: 1, can_start: true, blocked_reason: null,
  active_attempt_id: null, counted_score: null, best_score: null, first_score: null, ...over,
});
const single: CompetitionRules = { replay_mode: 'single_attempt', max_attempts: 1, score_rule: 'first', normal_play_policy: 'blocked' };
const multi: CompetitionRules = { replay_mode: 'multiple_attempts', max_attempts: 3, score_rule: 'best', normal_play_policy: 'allowed' };
const noReplay: CompetitionRules = { replay_mode: 'no_replay', max_attempts: 1, score_rule: 'first', normal_play_policy: 'blocked' };

describe('competitionPackAction (AT-MOB-03)', () => {
  it('start — "Start" for one attempt, "Start attempt N" in multiple mode', () => {
    expect(competitionPackAction(state(), 'active', single)).toEqual({ kind: 'start', attemptNumber: 1, numbered: false });
    expect(competitionPackAction(state({ attempts_used: 1, next_attempt_number: 2 }), 'active', multi))
      .toEqual({ kind: 'start', attemptNumber: 2, numbered: true });
  });

  it('an active attempt always means Continue (even with attempt_in_progress as blocked reason)', () => {
    expect(competitionPackAction(state({ active_attempt_id: 91, can_start: false, blocked_reason: 'attempt_in_progress' }), 'active', multi))
      .toEqual({ kind: 'continue', attemptId: 91 });
  });

  it.each([
    ['not_started', 'clubs.competition.blocked.not_started'],
    ['ended', 'clubs.competition.blocked.ended'],
    ['limit_reached', 'clubs.competition.blocked.limit_reached'],
    ['already_played', 'clubs.competition.blocked.already_played'],
    ['attempt_in_progress', 'clubs.competition.blocked.attempt_in_progress'],
    ['club_unavailable', 'clubs.competition.blocked.club_unavailable'],
  ] as const)('blocked_reason %s → %s', (reason, key) => {
    expect(competitionPackAction(state({ can_start: false, blocked_reason: reason, next_attempt_number: null }), 'active', single))
      .toEqual({ kind: 'blocked', reasonKey: key });
    expect(translate(key)).not.toBe(key);
  });

  it('cannot start without a next attempt number, or with an unknown reason → generic copy', () => {
    expect(competitionPackAction(state({ can_start: true, next_attempt_number: null }), 'active', single))
      .toEqual({ kind: 'blocked', reasonKey: 'clubs.competition.blocked.unavailable' });
    expect(blockedReasonKey('something_new')).toBe('clubs.competition.blocked.unavailable');
    expect(blockedReasonKey(null)).toBe('clubs.competition.blocked.unavailable');
  });

  it.each(['upcoming', 'ended', 'finalized', 'cancelled', 'voided'] as const)('%s → no play button at all', (phase) => {
    expect(competitionPackAction(state({ active_attempt_id: 5 }), phase, multi)).toEqual({ kind: 'none' });
  });
});

describe('pack row labels', () => {
  it('attempts only in multiple mode', () => {
    expect(attemptsLabel(state({ attempts_used: 2 }), multi, 'en')).toBe('Attempts 2/3');
    expect(attemptsLabel(state(), single, 'en')).toBeNull();
  });

  it('counted + best (when different)', () => {
    expect(packScoreParts(state({ counted_score: 840, best_score: 840 }), multi, 'en')).toEqual(['Counted 840']);
    expect(packScoreParts(state({ counted_score: 500, best_score: 900 }), { ...multi, score_rule: 'first' }, 'en')).toEqual(['Counted 500', 'best 900']);
    expect(packScoreParts(state(), single, 'en')).toEqual([]);
  });
});

describe('phase + countdown', () => {
  const now = Date.parse('2026-11-10T12:00:00Z');

  it('groups phases', () => {
    expect(competitionGroup('ended')).toBe('completed');
    expect(competitionGroup('finalized')).toBe('completed');
    expect(competitionGroup('voided')).toBe('closed');
    expect(competitionGroup('active')).toBe('active');
  });

  it('counts down in whole minutes, rounding up', () => {
    expect(countdownTo('2026-11-13T16:30:00Z', now)).toEqual({ days: 3, hours: 4, minutes: 30, done: false });
    expect(countdownTo('2026-11-10T12:00:30Z', now)).toEqual({ days: 0, hours: 0, minutes: 1, done: false });
    expect(countdownTo('2026-11-10T11:59:00Z', now).done).toBe(true);
    expect(countdownTo('garbage', now).done).toBe(true);
  });

  it('shows the two largest units', () => {
    expect(formatCountdown({ days: 3, hours: 4, minutes: 30, done: false }, 'en')).toBe('3d 4h');
    expect(formatCountdown({ days: 3, hours: 0, minutes: 30, done: false }, 'en')).toBe('3d');
    expect(formatCountdown({ days: 0, hours: 4, minutes: 12, done: false }, 'en')).toBe('4h 12m');
    expect(formatCountdown({ days: 0, hours: 0, minutes: 7, done: false }, 'en')).toBe('7m');
  });

  it('status line per phase', () => {
    const c = { starts_at: '2026-11-12T12:00:00Z', ends_at: '2026-11-30T12:00:00Z' };
    expect(competitionStatusLine({ ...c, phase: 'upcoming' }, now, 'en')).toBe('Starts in 2d');
    expect(competitionStatusLine({ ...c, starts_at: '2026-11-01T00:00:00Z', phase: 'upcoming' }, now, 'en')).toBe('Starting now');
    expect(competitionStatusLine({ ...c, phase: 'active' }, now, 'en')).toBe('Ends in 20d');
    expect(competitionStatusLine({ ...c, ends_at: '2026-11-10T11:00:00Z', phase: 'active' }, now, 'en')).toBe('Ended');
    expect(competitionStatusLine({ ...c, phase: 'finalized' }, now, 'en')).toBe('Final results');
    expect(competitionStatusLine({ ...c, phase: 'cancelled' }, now, 'en')).toBe('Cancelled');
  });

  it('formats the window in the competition time zone', () => {
    expect(timeZoneLabel('Europe/Brussels')).toBe('Brussels');
    expect(timeZoneLabel('America/Argentina/Buenos_Aires')).toBe('Buenos Aires');
    expect(timeZoneLabel('')).toBe('');
    const w = competitionWindow({ starts_at: '2026-11-01T09:00:00Z', ends_at: '2026-11-30T21:00:00Z', timezone: 'Europe/Brussels' }, 'en');
    // 09:00Z = 10:00 in Brussels (CET), 21:00Z = 22:00.
    expect(w).toMatch(/10:00/);
    expect(w).toMatch(/22:00/);
    expect(w).toMatch(/\(Brussels\)$/);
  });

  it('an unknown time zone never throws', () => {
    expect(() => competitionWindow({ starts_at: '2026-11-01T09:00:00Z', ends_at: '2026-11-30T21:00:00Z', timezone: 'Mars/Olympus' }, 'en')).not.toThrow();
  });
});

describe('rules description', () => {
  it('one line per rule, for all three replay modes', () => {
    expect(describeRules(single).map((l) => l.key)).toEqual([
      'clubs.competition.rules.oneAttempt', 'clubs.competition.rules.normalBlocked', 'clubs.competition.rules.window',
    ]);
    expect(describeRules(noReplay)[0].key).toBe('clubs.competition.rules.noReplay');
    expect(describeRules(multi)[0]).toEqual({ key: 'clubs.competition.rules.bestOf', params: { max: 3 } });
    expect(describeRules({ ...multi, score_rule: 'first' })[0]).toEqual({ key: 'clubs.competition.rules.firstOf', params: { max: 3 } });
    expect(describeRules(multi)[1].key).toBe('clubs.competition.rules.normalAllowed');
  });

  it('reads naturally', () => {
    expect(describeRulesText(multi, 'en')[0]).toBe('Best of 3 attempts counts.');
    expect(describeRulesText({ ...multi, score_rule: 'first' }, 'en')[0]).toMatch(/first attempt counts/);
    expect(describeRulesText(single, 'en')[0]).toBe('One attempt per pack.');
    expect(describeRulesText(noReplay, 'en')[0]).toMatch(/^No replay — packs you already completed can't be played/);
  });

  it('a "multiple" mode with max 1 reads as one attempt', () => {
    expect(describeRules({ ...multi, max_attempts: 1 })[0].key).toBe('clubs.competition.rules.oneAttempt');
  });
});

describe('mapping onto the pack play shapes', () => {
  const attempt: CompetitionAttempt = {
    id: 91, competition_id: '01J', pack_slug: 'autumn', attempt_number: 2, status: 'active', current_index: 3,
    completed_count: 3, total_challenges: 8, total_score: 240, is_rewarded_run: false, started_at: 'x', completed_at: null,
  };

  it('attempt state', () => {
    expect(toPackAttemptState(attempt)).toEqual({
      id: 91, status: 'active', current_index: 3, total_score: 240, completed_count: 3, total_challenges: 8, started_at: 'x', completed_at: null,
    });
    expect(toPackAttemptState({ ...attempt, status: 'expired' }).status).toBe('abandoned');
    expect(toPackAttemptState({ ...attempt, status: 'voided' }).status).toBe('abandoned');
    expect(toPackAttemptState({ ...attempt, status: 'completed' }).status).toBe('completed');
    expect(toPackStartResponse({ attempt, challenge: null })).toEqual({ attempt: toPackAttemptState(attempt), challenge: null, completion: null });
  });

  it('guess result: replayed → already counted, reward flag from the attempt, competition block kept', () => {
    const base = {
      result: { score: 80, distance: 1, guessed_x: 0.1, guessed_y: 0.2, ball_x_ratio: 0.1, ball_y_ratio: 0.2, reveal_image_url: null },
      next_challenge: null, rank_progress: { xp_gained: 0 } as PackGuessResult['rank_progress'], rank_up: null, new_badges: [],
      pack_completed: true, final_score: 320, completion_xp: 0,
    };
    const mapped = toPackGuessResult({
      ...base, progress: { ...attempt, status: 'completed' }, replayed: true,
      competition: { counted_score: 320, total_score: 2140, rank: 4, attempts_remaining: 1 },
    });
    expect(mapped.progress.status).toBe('completed');
    expect(mapped.already_completed).toBe(true);
    expect(mapped.is_rewarded_run).toBe(false);
    expect(mapped.competition).toEqual({ counted_score: 320, total_score: 2140, rank: 4, attempts_remaining: 1 });
    expect(isRewardedRun(mapped)).toBe(false);
  });

  it('reward inference when the flag is absent', () => {
    expect(isRewardedRun({ is_rewarded_run: null, completion_xp: 50, rank_progress: undefined as never })).toBe(true);
    expect(isRewardedRun({ completion_xp: 0, rank_progress: { xp_gained: 0 } as PackGuessResult['rank_progress'] })).toBe(false);
    expect(isRewardedRun(null)).toBe(true);
  });
});
