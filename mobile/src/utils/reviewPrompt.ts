/**
 * Store review prompt policy (v1.9.12 growth). Pure TS, jest-tested.
 *
 * Two flows share this state:
 *  - the AUTOMATIC native review prompt: considered only after a positive
 *    moment, never on the first launch, never after reviewPromptCompleted,
 *    and it never opens the store page as a fallback. The 30-day cooldown
 *    and the 3-attempts-per-install cap only matter BEFORE the device is
 *    completed (an attempt where the native dialog could not even be
 *    requested, e.g. no Play Store);
 *  - the MANUAL "Rate BallPicker" button: native review where available,
 *    otherwise the store page.
 *
 * reviewPromptCompleted is the product rule and it is final: it becomes true
 * as soon as the review flow was shown to the user — a native review request
 * attempted automatically, Rate BallPicker tapped, or the store fallback
 * opened — and from then on the automatic prompt is blocked forever on that
 * device.
 *
 * Neither flow grants XP, a trophy, a badge, an unlock or any other benefit —
 * store reviews must never be incentivized (REVIEW_REWARD_XP is 0 by design
 * and the rate flow never calls the share/reward API).
 */

export const REVIEW_PROMPT_COOLDOWN_DAYS = 30;
export const REVIEW_PROMPT_MAX_ATTEMPTS = 3;
/** Daily Challenge score that counts as a positive moment. */
export const REVIEW_PROMPT_SCORE_THRESHOLD = 80;
/** Completed-challenge counts that count as a positive moment. */
export const REVIEW_PROMPT_CHALLENGE_MILESTONES: readonly number[] = [3, 5];
/** Below this, a completion is a "low score" and never prompts. */
export const REVIEW_PROMPT_LOW_SCORE = 40;
/** XP / trophies for rating or reviewing the app: none, ever. */
export const REVIEW_REWARD_XP = 0 as const;

const DAY_MS = 24 * 60 * 60 * 1000;

export interface ReviewPromptState {
  reviewPromptCompleted: boolean;
  reviewPromptAttemptCount: number;
  lastReviewPromptAt: string | null;
  completedChallengeCount: number;
  /** App launches on this install; the prompt is never considered on launch 1. */
  appLaunchCount: number;
}

export const INITIAL_REVIEW_PROMPT_STATE: ReviewPromptState = {
  reviewPromptCompleted: false,
  reviewPromptAttemptCount: 0,
  lastReviewPromptAt: null,
  completedChallengeCount: 0,
  appLaunchCount: 0,
};

/** What just happened. Negative moments are listed so callers can be explicit. */
export type ReviewMoment =
  | { kind: 'daily_score'; score: number }
  | { kind: 'challenge_completed'; count: number; score?: number }
  | { kind: 'pack_completed' }
  | { kind: 'error' }
  | { kind: 'failed_share' }
  | { kind: 'failed_upload' }
  | { kind: 'failed_login' };

export type ReviewSkipEvent = 'review_prompt_skipped_due_to_completed' | 'review_prompt_skipped_due_to_cooldown';

export type ReviewDecision =
  | { show: true; reason: 'positive_moment' }
  | { show: false; reason: 'not_positive' | 'first_launch' | 'completed' | 'cooldown' | 'cap'; skipEvent?: ReviewSkipEvent };

/** Only these moments can ever lead to a prompt. */
export function isPositiveMoment(moment: ReviewMoment): boolean {
  switch (moment.kind) {
    case 'daily_score':
      return Number.isFinite(moment.score) && moment.score >= REVIEW_PROMPT_SCORE_THRESHOLD;
    case 'challenge_completed':
      if (!REVIEW_PROMPT_CHALLENGE_MILESTONES.includes(moment.count)) return false;
      return moment.score === undefined || moment.score >= REVIEW_PROMPT_LOW_SCORE;
    case 'pack_completed':
      return true;
    default:
      return false;
  }
}

/** Automatic-prompt policy. Order: positive? → completed → first launch → cap → cooldown. */
export function decideReviewPrompt(state: ReviewPromptState, moment: ReviewMoment, now: Date): ReviewDecision {
  if (!isPositiveMoment(moment)) return { show: false, reason: 'not_positive' };
  if (state.reviewPromptCompleted) {
    return { show: false, reason: 'completed', skipEvent: 'review_prompt_skipped_due_to_completed' };
  }
  if (state.appLaunchCount <= 1) return { show: false, reason: 'first_launch' };
  if (state.reviewPromptAttemptCount >= REVIEW_PROMPT_MAX_ATTEMPTS) return { show: false, reason: 'cap' };
  if (state.lastReviewPromptAt) {
    const last = Date.parse(state.lastReviewPromptAt);
    if (Number.isFinite(last) && now.getTime() - last < REVIEW_PROMPT_COOLDOWN_DAYS * DAY_MS) {
      return { show: false, reason: 'cooldown', skipEvent: 'review_prompt_skipped_due_to_cooldown' };
    }
  }
  return { show: true, reason: 'positive_moment' };
}

export function markPromptAttempted(state: ReviewPromptState, now: Date): ReviewPromptState {
  return {
    ...state,
    reviewPromptAttemptCount: state.reviewPromptAttemptCount + 1,
    lastReviewPromptAt: now.toISOString(),
  };
}

export function markReviewCompleted(state: ReviewPromptState): ReviewPromptState {
  return { ...state, reviewPromptCompleted: true };
}

/**
 * The automatic flow is about to request (or just requested) the native
 * review dialog. The user has now been shown the review flow, so the device
 * is completed for good — whatever the OS does with the request and even if
 * the request itself fails.
 */
export function markNativeReviewRequested(state: ReviewPromptState): ReviewPromptState {
  return markReviewCompleted(state);
}

export function incrementCompletedChallenges(state: ReviewPromptState): ReviewPromptState {
  return { ...state, completedChallengeCount: state.completedChallengeCount + 1 };
}

export function noteAppLaunch(state: ReviewPromptState): ReviewPromptState {
  return { ...state, appLaunchCount: state.appLaunchCount + 1 };
}

// ---------------------------------------------------------------------------
// Native review vs. store page
// ---------------------------------------------------------------------------

export type RateAction = 'native_review' | 'open_store' | 'unavailable';

/** Manual Rate button: native review first, the store page only as a fallback. */
export function rateButtonAction(input: { nativeAvailable: boolean; storeUrl: string | null }): RateAction {
  if (input.nativeAvailable) return 'native_review';
  if (input.storeUrl) return 'open_store';
  return 'unavailable';
}

/** Automatic prompt: native review or nothing — it never opens the store page. */
export function autoPromptAction(nativeAvailable: boolean): 'native_review' | 'skip' {
  return nativeAvailable ? 'native_review' : 'skip';
}

export interface RateButtonResult {
  action: RateAction;
  state: ReviewPromptState;
  /** Always 0 — rating is never rewarded. */
  xpAwarded: 0;
  /** Always empty — rating never unlocks anything. */
  badgesAwarded: never[];
}

/**
 * The user tapped Rate BallPicker: decide the action and mark the device as
 * completed so the automatic prompt never shows again. No reward of any kind.
 */
export function completeRateButtonTap(
  state: ReviewPromptState,
  input: { nativeAvailable: boolean; storeUrl: string | null },
): RateButtonResult {
  return {
    action: rateButtonAction(input),
    state: markReviewCompleted(state),
    xpAwarded: REVIEW_REWARD_XP,
    badgesAwarded: [],
  };
}
