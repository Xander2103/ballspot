import { apiClient } from './client';

/** A single rung on the rank ladder (config-driven, from GET /api/ranks). */
export interface RankThreshold {
  name: string;
  level: number;
  min_xp: number;
}

export const rankApi = {
  // Every configured rank in ascending XP order.
  all: () => apiClient.request<{ data: RankThreshold[] }>('/ranks').then((r) => r.data),
};
