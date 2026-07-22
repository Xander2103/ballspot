import { apiClient } from './client';
import type { Sport } from '../types/sport';

export const sportApi = {
  // GET /sports → JsonResource collection { data: Sport[] }.
  // Returns active (selectable) + coming_soon (visible, disabled) sports.
  list: () =>
    apiClient.request<{ data: Sport[] }>('/sports').then((r) => r.data),
};
