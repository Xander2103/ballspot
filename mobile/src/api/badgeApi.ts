import { apiClient } from './client';
import type { Badge, BadgeCollection } from '../types/badge';

export const badgeApi = {
  // Full catalogue of virtual trophies.
  all: () => apiClient.request<{ data: Badge[] }>('/badges').then((r) => r.data),

  // Every badge with earned state for the current user.
  mine: () => apiClient.request<BadgeCollection>('/me/badges'),
};
