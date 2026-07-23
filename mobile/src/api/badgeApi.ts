import { apiClient } from './client';
import type { Badge, BadgeCollection, CompetitionFinish, TournamentFinish } from '../types/badge';

export const badgeApi = {
  // Full catalogue of virtual trophies.
  all: () => apiClient.request<{ data: Badge[] }>('/badges').then((r) => r.data),

  // Every badge with earned state for the current user.
  mine: () => apiClient.request<BadgeCollection>('/me/badges'),

  // The current user's tournament placements (Trophy Room finishes).
  finishes: () => apiClient.request<{ data: TournamentFinish[] }>('/me/tournament-finishes').then((r) => r.data),

  // Closed-period competition placements (Trophy Room competition trophies).
  competitionFinishes: () => apiClient.request<{ data: CompetitionFinish[] }>('/me/competition-finishes').then((r) => r.data),
};
