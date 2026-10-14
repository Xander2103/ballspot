import { apiClient } from './client';
import type { Badge } from '../types/badge';
import type { RankProgress, RankUp } from '../types/auth';
import type { ShareKind, ShareReward, ShareRewardStatus } from '../utils/share';

/** Review / rate telemetry names the backend accepts (never rewarded). */
export type GrowthEventName =
  | 'rate_app_tapped'
  | 'review_prompt_attempted'
  | 'native_review_requested'
  | 'review_prompt_skipped_due_to_completed'
  | 'review_prompt_skipped_due_to_cooldown';

export type GrowthTrigger = 'daily_score' | 'challenge_milestone' | 'pack_completed' | 'button';

export interface ShareClaimResponse {
  share_count: number;
  xp_awarded: number;
  rewards: ShareReward[];
  new_badges: Badge[];
  rank_progress: RankProgress;
  rank_up: RankUp | null;
  status: ShareRewardStatus;
}

export const growthApi = {
  /** The native share sheet was opened: record the share + claim what is available. */
  share: (payload: { type: ShareKind; daily_challenge_id?: number }) =>
    apiClient.request<ShareClaimResponse>('/share', { method: 'POST', body: JSON.stringify(payload) }),

  /** What sharing can still earn today (server-authoritative). */
  shareStatus: () => apiClient.request<ShareRewardStatus>('/share/status'),

  /**
   * Fire-and-forget telemetry. Never throws and never blocks the UI — a
   * dropped event is just a dropped counter.
   */
  track: async (event: GrowthEventName, metadata?: { score?: number; trigger?: GrowthTrigger }): Promise<void> => {
    try {
      await apiClient.request('/events', { method: 'POST', body: JSON.stringify({ event, metadata }) });
    } catch {
      // best-effort
    }
  },
};
