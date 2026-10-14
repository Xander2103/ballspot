import {
  autoPromptAction, completeRateButtonTap, decideReviewPrompt, incrementCompletedChallenges, INITIAL_REVIEW_PROMPT_STATE,
  isPositiveMoment, markNativeReviewRequested, markPromptAttempted, markReviewCompleted, noteAppLaunch, rateButtonAction,
  REVIEW_PROMPT_COOLDOWN_DAYS, REVIEW_PROMPT_MAX_ATTEMPTS, REVIEW_REWARD_XP, type ReviewPromptState,
} from '../reviewPrompt';

const NOW = new Date('2026-10-14T12:00:00Z');
const daysAgo = (n: number) => new Date(NOW.getTime() - n * 24 * 60 * 60 * 1000).toISOString();

/** A device past its first launch with nothing else recorded. */
const READY: ReviewPromptState = { ...INITIAL_REVIEW_PROMPT_STATE, appLaunchCount: 2 };

describe('positive moments', () => {
  it('score >= 80, the 3rd / 5th completed challenge and a completed pack are positive', () => {
    expect(isPositiveMoment({ kind: 'daily_score', score: 80 })).toBe(true);
    expect(isPositiveMoment({ kind: 'challenge_completed', count: 3, score: 60 })).toBe(true);
    expect(isPositiveMoment({ kind: 'challenge_completed', count: 5 })).toBe(true);
    expect(isPositiveMoment({ kind: 'pack_completed' })).toBe(true);
  });

  it('errors, failed flows, low scores and other counts are never positive', () => {
    expect(isPositiveMoment({ kind: 'daily_score', score: 79 })).toBe(false);
    expect(isPositiveMoment({ kind: 'challenge_completed', count: 4, score: 100 })).toBe(false);
    expect(isPositiveMoment({ kind: 'challenge_completed', count: 3, score: 10 })).toBe(false); // low score
    expect(isPositiveMoment({ kind: 'error' })).toBe(false);
    expect(isPositiveMoment({ kind: 'failed_share' })).toBe(false);
    expect(isPositiveMoment({ kind: 'failed_upload' })).toBe(false);
    expect(isPositiveMoment({ kind: 'failed_login' })).toBe(false);
  });
});

describe('automatic review prompt policy', () => {
  it('never prompts on the first launch', () => {
    const firstLaunch = noteAppLaunch(INITIAL_REVIEW_PROMPT_STATE);
    expect(firstLaunch.appLaunchCount).toBe(1);
    expect(decideReviewPrompt(firstLaunch, { kind: 'daily_score', score: 100 }, NOW)).toEqual({ show: false, reason: 'first_launch' });
    expect(decideReviewPrompt(INITIAL_REVIEW_PROMPT_STATE, { kind: 'pack_completed' }, NOW).show).toBe(false);
  });

  it('allows a prompt after a daily score >= 80', () => {
    expect(decideReviewPrompt(READY, { kind: 'daily_score', score: 80 }, NOW)).toEqual({ show: true, reason: 'positive_moment' });
    expect(decideReviewPrompt(READY, { kind: 'daily_score', score: 79 }, NOW)).toEqual({ show: false, reason: 'not_positive' });
  });

  it('allows a prompt after 3 completed challenges', () => {
    let state = READY;
    for (let i = 0; i < 3; i++) state = incrementCompletedChallenges(state);
    expect(state.completedChallengeCount).toBe(3);
    expect(decideReviewPrompt(state, { kind: 'challenge_completed', count: state.completedChallengeCount, score: 50 }, NOW).show).toBe(true);
  });

  it('allows a prompt after 5 completed challenges', () => {
    const state = { ...READY, completedChallengeCount: 5 };
    expect(decideReviewPrompt(state, { kind: 'challenge_completed', count: 5, score: 70 }, NOW).show).toBe(true);
    expect(decideReviewPrompt(state, { kind: 'challenge_completed', count: 6, score: 70 }, NOW).show).toBe(false);
  });

  it('allows a prompt after a completed pack', () => {
    expect(decideReviewPrompt(READY, { kind: 'pack_completed' }, NOW).show).toBe(true);
  });

  it('never prompts after errors or a low score', () => {
    expect(decideReviewPrompt(READY, { kind: 'error' }, NOW).show).toBe(false);
    expect(decideReviewPrompt(READY, { kind: 'failed_share' }, NOW).show).toBe(false);
    expect(decideReviewPrompt(READY, { kind: 'daily_score', score: 20 }, NOW).show).toBe(false);
  });

  it('cooldown blocks a repeat prompt for 30 days (before the device is completed)', () => {
    expect(REVIEW_PROMPT_COOLDOWN_DAYS).toBe(30);
    // An attempt where the native dialog could not be requested (no Play
    // Store, for instance) does not complete the device — cooldown applies.
    const attempted = markPromptAttempted(READY, new Date(daysAgo(10)));
    expect(attempted.reviewPromptCompleted).toBe(false);
    expect(decideReviewPrompt(attempted, { kind: 'daily_score', score: 95 }, NOW)).toEqual({
      show: false, reason: 'cooldown', skipEvent: 'review_prompt_skipped_due_to_cooldown',
    });
    const longAgo = { ...attempted, lastReviewPromptAt: daysAgo(31) };
    expect(decideReviewPrompt(longAgo, { kind: 'daily_score', score: 95 }, NOW).show).toBe(true);
  });

  it('an automatic native review request sets reviewPromptCompleted', () => {
    const attempted = markPromptAttempted(READY, NOW);
    const requested = markNativeReviewRequested(attempted);
    expect(requested.reviewPromptCompleted).toBe(true);
    expect(requested.reviewPromptAttemptCount).toBe(1);
    expect(requested.lastReviewPromptAt).toBe(NOW.toISOString());
    // Completion wins over the cooldown having expired and the cap being far away.
    const later = new Date(NOW.getTime() + 400 * 24 * 60 * 60 * 1000);
    expect(decideReviewPrompt(requested, { kind: 'daily_score', score: 100 }, later)).toEqual({
      show: false, reason: 'completed', skipEvent: 'review_prompt_skipped_due_to_completed',
    });
    expect(decideReviewPrompt(requested, { kind: 'pack_completed' }, later).show).toBe(false);
    expect(decideReviewPrompt(requested, { kind: 'challenge_completed', count: 5 }, later).show).toBe(false);
  });

  it('hard cap blocks a repeat prompt after 3 attempts per install', () => {
    expect(REVIEW_PROMPT_MAX_ATTEMPTS).toBe(3);
    let state = READY;
    for (let i = 0; i < 3; i++) state = markPromptAttempted(state, new Date(daysAgo(100 - i * 31)));
    state = { ...state, lastReviewPromptAt: daysAgo(400) }; // cooldown long over
    expect(state.reviewPromptAttemptCount).toBe(3);
    expect(decideReviewPrompt(state, { kind: 'daily_score', score: 100 }, NOW)).toEqual({ show: false, reason: 'cap' });
  });

  it('reviewPromptCompleted blocks the prompt forever', () => {
    const done = markReviewCompleted({ ...READY, lastReviewPromptAt: daysAgo(400), reviewPromptAttemptCount: 0 });
    expect(decideReviewPrompt(done, { kind: 'daily_score', score: 100 }, NOW)).toEqual({
      show: false, reason: 'completed', skipEvent: 'review_prompt_skipped_due_to_completed',
    });
    expect(decideReviewPrompt(done, { kind: 'pack_completed' }, new Date('2030-01-01T00:00:00Z')).show).toBe(false);
    // Nothing un-completes a device: more launches, more challenges, time.
    const stillDone = incrementCompletedChallenges(noteAppLaunch({ ...done, reviewPromptAttemptCount: 0, lastReviewPromptAt: null }));
    expect(decideReviewPrompt(stillDone, { kind: 'daily_score', score: 100 }, new Date('2035-01-01T00:00:00Z')).show).toBe(false);
  });

  it('completion is the stricter rule: cooldown and cap only matter before it', () => {
    // Before completion: cooldown applies.
    const cooling = markPromptAttempted(READY, new Date(daysAgo(1)));
    expect(decideReviewPrompt(cooling, { kind: 'daily_score', score: 90 }, NOW).reason).toBe('cooldown');
    // After completion: the reason is always 'completed', never cooldown/cap.
    const completed = markNativeReviewRequested(cooling);
    expect(decideReviewPrompt(completed, { kind: 'daily_score', score: 90 }, NOW).reason).toBe('completed');
    const capped = { ...completed, reviewPromptAttemptCount: REVIEW_PROMPT_MAX_ATTEMPTS };
    expect(decideReviewPrompt(capped, { kind: 'daily_score', score: 90 }, NOW).reason).toBe('completed');
  });

  it('records an attempt with its timestamp', () => {
    const next = markPromptAttempted(READY, NOW);
    expect(next.reviewPromptAttemptCount).toBe(1);
    expect(next.lastReviewPromptAt).toBe(NOW.toISOString());
    expect(next.reviewPromptCompleted).toBe(false);
  });
});

describe('Rate BallPicker button', () => {
  it('sets reviewPromptCompleted and never grants XP or trophies', () => {
    expect(REVIEW_REWARD_XP).toBe(0);
    const result = completeRateButtonTap(READY, { nativeAvailable: true, storeUrl: null });
    expect(result.state.reviewPromptCompleted).toBe(true);
    expect(result.xpAwarded).toBe(0);
    expect(result.badgesAwarded).toEqual([]);
    expect(result.action).toBe('native_review');
    // And from then on the automatic prompt is blocked.
    expect(decideReviewPrompt(result.state, { kind: 'daily_score', score: 100 }, NOW).show).toBe(false);
  });

  it('opens the store page only when native review is unavailable', () => {
    expect(rateButtonAction({ nativeAvailable: true, storeUrl: 'https://play.google.com/store/apps/details?id=x' })).toBe('native_review');
    expect(rateButtonAction({ nativeAvailable: false, storeUrl: 'https://play.google.com/store/apps/details?id=x' })).toBe('open_store');
    expect(rateButtonAction({ nativeAvailable: false, storeUrl: null })).toBe('unavailable');
  });

  it('the manual store fallback also sets reviewPromptCompleted', () => {
    const result = completeRateButtonTap(READY, { nativeAvailable: false, storeUrl: 'https://play.google.com/store/apps/details?id=x' });
    expect(result.action).toBe('open_store');
    expect(result.state.reviewPromptCompleted).toBe(true);
    expect(result.xpAwarded).toBe(0);
    expect(decideReviewPrompt(result.state, { kind: 'pack_completed' }, NOW).reason).toBe('completed');
  });

  it('native review failure does not crash and still completes the device', () => {
    const result = completeRateButtonTap(READY, { nativeAvailable: false, storeUrl: null });
    expect(result.action).toBe('unavailable');
    expect(result.state.reviewPromptCompleted).toBe(true);
    expect(result.xpAwarded).toBe(0);
  });
});

describe('automatic prompt fallback', () => {
  it('never auto-opens the store page when native review is unavailable', () => {
    expect(autoPromptAction(false)).toBe('skip');
    expect(autoPromptAction(true)).toBe('native_review');
  });
});
