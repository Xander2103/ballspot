import { apiClient } from './client';
import { CurrentRoundResponse } from '../types/challenge';
import { GuessResult } from '../types/guess';

export const roundApi = {
  currentRound: (leagueId: number) =>
    apiClient.request<CurrentRoundResponse>(`/leagues/${leagueId}/current-round`),

  submitGuess: (roundId: number, data: { guess_x_ratio: number; guess_y_ratio: number }) =>
    apiClient.request<GuessResult>(`/rounds/${roundId}/guess`, { method: 'POST', body: JSON.stringify(data) }),

  result: (roundId: number) =>
    apiClient.request<GuessResult>(`/rounds/${roundId}/result`),
};
