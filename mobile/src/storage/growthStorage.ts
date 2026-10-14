import { localFlags } from './localFlags';
import { INITIAL_REVIEW_PROMPT_STATE, type ReviewPromptState } from '../utils/reviewPrompt';
import { INITIAL_SHARE_REWARD_STATE, type ShareRewardState } from '../utils/share';

/**
 * Local persistence for the growth features (review prompt policy + share
 * reward mirror). Non-secret device flags through the existing localFlags
 * helper (SecureStore on device, localStorage on web); every call is
 * best-effort and never throws.
 */
export const REVIEW_PROMPT_KEYS = {
  completed: 'reviewPromptCompleted',
  attemptCount: 'reviewPromptAttemptCount',
  lastPromptAt: 'lastReviewPromptAt',
  completedChallengeCount: 'completedChallengeCount',
  appLaunchCount: 'appLaunchCount',
} as const;

/** One JSON blob: totalShareCount, firstShareClaimed, lastDailyShareRewardDate, lastHighScoreShareRewardDate. */
export const SHARE_REWARD_STATE_KEY = 'shareRewardState';

function toInt(value: string | null): number {
  const n = Number.parseInt(value ?? '', 10);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

export async function loadReviewPromptState(): Promise<ReviewPromptState> {
  const [completed, attempts, lastAt, challenges, launches] = await Promise.all([
    localFlags.get(REVIEW_PROMPT_KEYS.completed),
    localFlags.get(REVIEW_PROMPT_KEYS.attemptCount),
    localFlags.get(REVIEW_PROMPT_KEYS.lastPromptAt),
    localFlags.get(REVIEW_PROMPT_KEYS.completedChallengeCount),
    localFlags.get(REVIEW_PROMPT_KEYS.appLaunchCount),
  ]);
  return {
    ...INITIAL_REVIEW_PROMPT_STATE,
    reviewPromptCompleted: completed === '1',
    reviewPromptAttemptCount: toInt(attempts),
    lastReviewPromptAt: lastAt && Number.isFinite(Date.parse(lastAt)) ? lastAt : null,
    completedChallengeCount: toInt(challenges),
    appLaunchCount: toInt(launches),
  };
}

export async function saveReviewPromptState(state: ReviewPromptState): Promise<void> {
  await Promise.all([
    localFlags.set(REVIEW_PROMPT_KEYS.completed, state.reviewPromptCompleted ? '1' : '0'),
    localFlags.set(REVIEW_PROMPT_KEYS.attemptCount, String(state.reviewPromptAttemptCount)),
    state.lastReviewPromptAt
      ? localFlags.set(REVIEW_PROMPT_KEYS.lastPromptAt, state.lastReviewPromptAt)
      : localFlags.remove(REVIEW_PROMPT_KEYS.lastPromptAt),
    localFlags.set(REVIEW_PROMPT_KEYS.completedChallengeCount, String(state.completedChallengeCount)),
    localFlags.set(REVIEW_PROMPT_KEYS.appLaunchCount, String(state.appLaunchCount)),
  ]);
}

export async function loadShareRewardState(): Promise<ShareRewardState> {
  const raw = await localFlags.get(SHARE_REWARD_STATE_KEY);
  if (!raw) return INITIAL_SHARE_REWARD_STATE;
  try {
    const parsed = JSON.parse(raw) as Partial<ShareRewardState>;
    return {
      totalShareCount: typeof parsed.totalShareCount === 'number' && parsed.totalShareCount >= 0 ? parsed.totalShareCount : 0,
      firstShareClaimed: parsed.firstShareClaimed === true,
      lastDailyShareRewardDate: typeof parsed.lastDailyShareRewardDate === 'string' ? parsed.lastDailyShareRewardDate : null,
      lastHighScoreShareRewardDate: typeof parsed.lastHighScoreShareRewardDate === 'string' ? parsed.lastHighScoreShareRewardDate : null,
    };
  } catch {
    return INITIAL_SHARE_REWARD_STATE;
  }
}

export async function saveShareRewardState(state: ShareRewardState): Promise<void> {
  await localFlags.set(SHARE_REWARD_STATE_KEY, JSON.stringify(state));
}
