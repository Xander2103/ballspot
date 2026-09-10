/**
 * Friendly handling for the "not enough tournament content" state.
 *
 * The backend answers create/start with a structured 422 when the eligible
 * photo pool is short:
 *   { message, code: 'TOURNAMENTS_TEMPORARILY_UNAVAILABLE',
 *     reason: 'INSUFFICIENT_TOURNAMENT_CHALLENGES', required, available }
 * and GET /tournaments/availability reports the same state up front. The app
 * never shows the raw 422: the CODE maps to translated copy here.
 */

import { getApiErrorMessage } from './apiError';
import { translate } from '../i18n/core';

export const TOURNAMENTS_UNAVAILABLE_CODE = 'TOURNAMENTS_TEMPORARILY_UNAVAILABLE';

export interface TournamentAvailability {
  available: boolean;
  required: number;
  available_challenges: number;
  message: string | null;
  sport?: { id: number; slug: string; name: string } | null;
}

export interface TournamentsUnavailableError {
  status?: number;
  code: typeof TOURNAMENTS_UNAVAILABLE_CODE;
  reason?: string;
  required?: number;
  available?: number;
  message?: string;
}

export function isTournamentsUnavailable(e: unknown): e is TournamentsUnavailableError {
  return !!e && typeof e === 'object' && (e as { code?: unknown }).code === TOURNAMENTS_UNAVAILABLE_CODE;
}

/** Title + body for the info card / alert. */
export function tournamentsUnavailableCopy(): { title: string; body: string } {
  return {
    title: translate('tournaments.unavailable.title'),
    body: translate('tournaments.unavailable.body'),
  };
}

/**
 * One sentence for a failed create/start: the unavailable code → translated
 * copy (never the raw server text); anything else → the usual API mapping.
 */
export function getTournamentErrorMessage(e: unknown, fallback: string): string {
  if (isTournamentsUnavailable(e)) return tournamentsUnavailableCopy().body;
  return getApiErrorMessage(e, fallback);
}
