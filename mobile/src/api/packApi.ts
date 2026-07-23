import { apiClient } from './client';
import type {
  ChallengePackSummary, ChallengePackDetail,
  PackStartResponse, PackAttemptResponse, PackGuessResult, PackCompletion,
} from '../types/pack';

export const packApi = {
  // Active + public packs, optionally scoped to a sport.
  list: (params?: { sportSlug?: string }) =>
    apiClient.request<{ data: ChallengePackSummary[] }>(
      `/packs${params?.sportSlug ? `?sport_slug=${encodeURIComponent(params.sportSlug)}` : ''}`
    ),

  get: (slug: string) =>
    apiClient.request<{ data: ChallengePackDetail }>(`/packs/${encodeURIComponent(slug)}`),

  // Start a new attempt or resume the active one.
  start: (slug: string) =>
    apiClient.request<PackStartResponse>(`/packs/${encodeURIComponent(slug)}/start`, { method: 'POST' }),

  // Active or latest attempt for a pack.
  attempt: (slug: string) =>
    apiClient.request<PackAttemptResponse>(`/packs/${encodeURIComponent(slug)}/attempt`),

  // Submit a guess for the current challenge in an attempt.
  submitGuess: (attemptId: number, challengeId: number, guessedX: number, guessedY: number) =>
    apiClient.request<PackGuessResult>(`/pack-attempts/${attemptId}/guess`, {
      method: 'POST',
      body: JSON.stringify({ challenge_id: challengeId, guessed_x: guessedX, guessed_y: guessedY }),
    }),

  // Completed packs for the Trophy Room.
  completions: () =>
    apiClient.request<{ data: PackCompletion[] }>('/me/pack-completions').then((r) => r.data),
};
