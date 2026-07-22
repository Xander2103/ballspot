import { apiClient } from './client';
import type { Sport } from '../types/sport';

export interface PreferencesResponse {
  preferred_sport: Sport | null;
  selected_theme: string;
  avatar_url: string | null;
  available_themes: string[];
}

export interface PreferencesUpdate {
  preferred_sport_id?: number | null;
  selected_theme?: string;
}

export const preferencesApi = {
  get: () => apiClient.request<PreferencesResponse>('/me/preferences'),

  update: (data: PreferencesUpdate) =>
    apiClient.request<PreferencesResponse>('/me/preferences', {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),
};
