import { apiClient } from './client';
import type { ChallengePackSummary, ChallengePackDetail } from '../types/pack';

export const packApi = {
  // Active + public packs, optionally scoped to a sport.
  list: (params?: { sportSlug?: string }) =>
    apiClient.request<{ data: ChallengePackSummary[] }>(
      `/packs${params?.sportSlug ? `?sport_slug=${encodeURIComponent(params.sportSlug)}` : ''}`
    ),

  get: (slug: string) =>
    apiClient.request<{ data: ChallengePackDetail }>(`/packs/${encodeURIComponent(slug)}`),
};
