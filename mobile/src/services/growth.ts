import { Linking, Platform, Share } from 'react-native';
import * as StoreReview from 'expo-store-review';
import { growthApi, type GrowthTrigger } from '../api/growthApi';
import {
  loadReviewPromptState, loadShareRewardState, saveReviewPromptState, saveShareRewardState,
} from '../storage/growthStorage';
import {
  completeRateButtonTap, decideReviewPrompt, incrementCompletedChallenges, isPositiveMoment,
  markNativeReviewRequested, markPromptAttempted, noteAppLaunch, type RateAction, type ReviewMoment,
} from '../utils/reviewPrompt';
import {
  buildAppShareMessage, buildDailyResultShareMessage, stateFromServerStatus,
  type ShareKind, type ShareReward, type ShareRewardState,
} from '../utils/share';
import { resolveStoreUrl } from '../utils/storeLinks';
import { devLog } from '../utils/devLog';
import type { Badge } from '../types/badge';
import type { RankUp } from '../types/auth';

/**
 * Growth features glue (v1.9.12): native share sheet + server share rewards,
 * the manual Rate button and the conservative automatic review prompt.
 *
 * Rules implemented here (policy lives in utils/reviewPrompt + utils/share):
 *  - opening the native share sheet IS the share (no proof of posting exists);
 *    the server records it and decides the XP;
 *  - rating / reviewing never grants anything and never calls the share API;
 *  - the automatic prompt never opens the store page; a native failure never
 *    crashes — every step is wrapped;
 *  - once the review flow was shown (native request attempted automatically,
 *    Rate BallPicker tapped, store fallback opened) reviewPromptCompleted is
 *    persisted and the automatic prompt never appears again on this device.
 */

export interface ShareOutcome {
  /** The native share sheet opened. false = nothing happened (no claim, no prompt). */
  opened: boolean;
  /** The server accepted the share (false when offline — nothing was awarded). */
  claimed: boolean;
  xpAwarded: number;
  rewards: ShareReward[];
  newBadges: Badge[];
  rankUp: RankUp | null;
  state: ShareRewardState | null;
}

const NOTHING: Omit<ShareOutcome, 'opened' | 'claimed'> = { xpAwarded: 0, rewards: [], newBadges: [], rankUp: null, state: null };

async function openShareSheet(message: string): Promise<boolean> {
  try {
    // iOS reports dismissed vs. shared, Android always "shared"; neither is
    // proof of posting, so the sheet opening is the completed action.
    await Share.share({ message });
    return true;
  } catch (e) {
    devLog(`[growth] share sheet failed: ${(e as Error)?.message ?? 'unknown'}`);
    return false;
  }
}

async function claimShare(kind: ShareKind, dailyChallengeId?: number): Promise<Omit<ShareOutcome, 'opened'>> {
  try {
    const res = await growthApi.share(kind === 'daily_result' ? { type: kind, daily_challenge_id: dailyChallengeId } : { type: kind });
    const state = stateFromServerStatus(res.status);
    await saveShareRewardState(state);
    return {
      claimed: true,
      xpAwarded: res.xp_awarded,
      rewards: res.rewards,
      newBadges: res.new_badges ?? [],
      rankUp: res.rank_up ?? null,
      state,
    };
  } catch (e) {
    // Offline / server down: the share still happened for the user; nothing
    // is awarded and the local state stays untouched (no phantom XP).
    devLog(`[growth] share claim failed: ${(e as { status?: number })?.status ?? 'network'}`);
    return { claimed: false, ...NOTHING };
  }
}

async function nativeReviewAvailable(): Promise<boolean> {
  try {
    return await StoreReview.isAvailableAsync();
  } catch {
    return false;
  }
}

/** Request the native dialog. true = the request was made (the OS may still not show it). */
async function requestNativeReview(): Promise<boolean> {
  try {
    await StoreReview.requestReview();
    return true;
  } catch (e) {
    devLog(`[growth] native review failed: ${(e as Error)?.message ?? 'unknown'}`);
    return false;
  }
}

function triggerFor(moment: ReviewMoment): GrowthTrigger {
  if (moment.kind === 'daily_score') return 'daily_score';
  if (moment.kind === 'pack_completed') return 'pack_completed';
  return 'challenge_milestone';
}

/**
 * Automatic prompt: pick the first positive moment, apply the policy, then
 * (maybe) request the native dialog. Never opens the store, never throws.
 * Attempting the native request completes the device for good; an attempt
 * where the dialog could not be requested only counts toward cooldown/cap.
 */
async function considerReviewPrompt(moments: ReviewMoment[]): Promise<void> {
  try {
    const moment = moments.find(isPositiveMoment);
    if (!moment) return;

    const state = await loadReviewPromptState();
    const decision = decideReviewPrompt(state, moment, new Date());
    if (!decision.show) {
      if (decision.skipEvent) void growthApi.track(decision.skipEvent, { trigger: triggerFor(moment) });
      return;
    }

    const attempted = markPromptAttempted(state, new Date());
    await saveReviewPromptState(attempted);
    void growthApi.track('review_prompt_attempted', { trigger: triggerFor(moment) });

    if (!(await nativeReviewAvailable())) return; // no store page from the automatic flow

    // The review flow is being shown: persist "completed" BEFORE the request
    // so a failure, a crash or a killed app cannot re-prompt this device.
    await saveReviewPromptState(markNativeReviewRequested(attempted));
    if (await requestNativeReview()) {
      void growthApi.track('native_review_requested', { trigger: triggerFor(moment) });
    }
  } catch (e) {
    devLog(`[growth] review prompt skipped: ${(e as Error)?.message ?? 'unknown'}`);
  }
}

async function bumpCompletedChallenges(): Promise<number> {
  const state = await loadReviewPromptState();
  const next = incrementCompletedChallenges(state);
  await saveReviewPromptState(next);
  return next.completedChallengeCount;
}

export const growth = {
  /** Call once per app start; launch 1 never prompts. */
  async noteAppLaunch(): Promise<void> {
    try {
      await saveReviewPromptState(noteAppLaunch(await loadReviewPromptState()));
    } catch {
      // best-effort
    }
  },

  /** Server status when reachable (it wins), otherwise the local mirror. */
  async loadShareState(): Promise<ShareRewardState> {
    try {
      const state = stateFromServerStatus(await growthApi.shareStatus());
      await saveShareRewardState(state);
      return state;
    } catch {
      return loadShareRewardState();
    }
  },

  async shareDailyResult(score: number, dailyChallengeId: number): Promise<ShareOutcome> {
    const opened = await openShareSheet(buildDailyResultShareMessage(score));
    if (!opened) return { opened: false, claimed: false, ...NOTHING };
    return { opened: true, ...(await claimShare('daily_result', dailyChallengeId)) };
  },

  async shareApp(): Promise<ShareOutcome> {
    const opened = await openShareSheet(buildAppShareMessage());
    if (!opened) return { opened: false, claimed: false, ...NOTHING };
    return { opened: true, ...(await claimShare('app')) };
  },

  /**
   * Manual Rate button. Opens the official store review page directly (the
   * native dialog is OS-quota-limited and can silently not appear, and it
   * never shows in TestFlight); the native request is only the fallback when
   * the page cannot be opened. Marks the device completed whatever happens
   * next. Returns xpAwarded: 0 — always.
   */
  async rateApp(): Promise<{ action: RateAction; xpAwarded: 0 }> {
    void growthApi.track('rate_app_tapped', { trigger: 'button' });
    const storeUrl = resolveStoreUrl(Platform.OS, {
      EXPO_PUBLIC_IOS_APP_STORE_URL: process.env.EXPO_PUBLIC_IOS_APP_STORE_URL,
    });
    const nativeAvailable = await nativeReviewAvailable();
    const result = completeRateButtonTap(await loadReviewPromptState(), { nativeAvailable, storeUrl });
    await saveReviewPromptState(result.state);

    let action = result.action;
    if (action === 'open_store' && storeUrl) {
      try {
        await Linking.openURL(storeUrl);
      } catch (e) {
        devLog(`[growth] store page failed: ${(e as Error)?.message ?? 'unknown'}`);
        action = nativeAvailable ? 'native_review' : 'unavailable';
      }
    }
    if (action === 'native_review') {
      if (await requestNativeReview()) {
        void growthApi.track('native_review_requested', { trigger: 'button' });
      } else {
        action = 'unavailable';
      }
    }
    return { action, xpAwarded: 0 };
  },

  /** A fresh Daily Challenge result (not a re-opened one). */
  async onDailyResult(score: number): Promise<void> {
    try {
      const count = await bumpCompletedChallenges();
      await considerReviewPrompt([
        { kind: 'daily_score', score },
        { kind: 'challenge_completed', count, score },
      ]);
    } catch {
      // never block the result screen
    }
  },

  /** A fresh tournament round result. */
  async onTournamentResult(score: number): Promise<void> {
    try {
      const count = await bumpCompletedChallenges();
      await considerReviewPrompt([{ kind: 'challenge_completed', count, score }]);
    } catch {
      // never block the result screen
    }
  },

  /** A pack was just completed. */
  async onPackCompleted(): Promise<void> {
    await considerReviewPrompt([{ kind: 'pack_completed' }]);
  },
};
