import { apiClient } from './client';
import { CurrentRoundResponse, CurrentRoundProgress } from '../types/challenge';
import { GuessResult } from '../types/guess';
import type { Badge } from '../types/badge';

export type { CurrentRoundProgress, CurrentRoundResponse };

export const roundApi = {
  // current-round uses response()->json() directly — no data wrapper
  currentRound: (leagueId: number) =>
    apiClient.request<CurrentRoundResponse>(`/leagues/${leagueId}/current-round`),

  // GuessResultResource wraps in { data: GuessResult }; new_badges is a sibling.
  submitGuess: (roundId: number, data: { guess_x_ratio: number; guess_y_ratio: number }) =>
    apiClient
      .request<{ data: GuessResult; new_badges?: Badge[] }>(`/rounds/${roundId}/guess`, { method: 'POST', body: JSON.stringify(data) })
      .then(r => ({ result: r.data, newBadges: r.new_badges ?? [] })),

  result: (roundId: number) =>
    apiClient.request<{ data: GuessResult }>(`/rounds/${roundId}/result`).then(r => r.data),
};
