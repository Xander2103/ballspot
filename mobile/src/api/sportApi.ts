import { apiClient } from './client';
import type { Sport } from '../types/sport';

export const sportApi = {
  // GET /sports → JsonResource collection { data: Sport[] }.
  // includeInactive lists "Coming soon" sports too (for onboarding cards).
  list: (includeInactive = false) =>
    apiClient
      .request<{ data: Sport[] }>(`/sports${includeInactive ? '?include_inactive=1' : ''}`)
      .then((r) => r.data),
};
