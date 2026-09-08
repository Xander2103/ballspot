import { apiClient } from './client';
import type { Sport } from '../types/sport';

export interface PreferencesResponse {
  preferred_sport: Sport | null;
  selected_theme: string;
  /** nl | en | fr | de | es */
  preferred_language: string;
  /** Optional email login code (2FA). */
  two_factor_enabled: boolean;
  avatar_url: string | null;
  available_themes: string[];
  available_languages: string[];
}

export interface PreferencesUpdate {
  preferred_sport_id?: number | null;
  selected_theme?: string;
  preferred_language?: string;
  two_factor_enabled?: boolean;
}

export const preferencesApi = {
  get: () => apiClient.request<PreferencesResponse>('/me/preferences'),

  update: (data: PreferencesUpdate) =>
    apiClient.request<PreferencesResponse>('/me/preferences', {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),
};
