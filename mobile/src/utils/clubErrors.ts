/**
 * Club error codes → translated copy (pure, unit tested).
 *
 * The backend answers club failures with `{ message, code }` and a fixed
 * status (docs/clubs-implementation-contracts.md §3.3). The app translates
 * the CODE, never the server sentence, like utils/authErrors.ts does.
 * Admin-only codes (stale_version, club_has_history, …) are not mapped: they
 * can't happen in the app and fall back to the generic API mapping.
 */
import { getApiErrorMessage } from './apiError';
import { translate } from '../i18n/core';

/** Member-facing codes the app has copy for (each key exists in all 5 locales). */
export const CLUB_ERROR_CODES = [
  'feature_disabled',
  'club_not_found',
  'club_forbidden',
  'club_code_invalid',
  'club_join_locked',
  'club_full',
  'club_membership_limit',
  'club_unavailable',
  'club_owner_must_transfer',
  'club_last_owner',
  'pack_reserved_for_competition',
  'competition_not_started',
  'competition_ended',
  'competition_attempt_limit',
  'competition_pack_already_played',
  'competition_attempt_in_progress',
  'competition_invalid_pack',
  'unexpected_challenge',
  'attempt_not_active',
] as const;

export type ClubErrorCode = (typeof CLUB_ERROR_CODES)[number];

const KNOWN = new Set<string>(CLUB_ERROR_CODES);

export function clubErrorCode(e: unknown): ClubErrorCode | null {
  const code = e && typeof e === 'object' ? (e as { code?: unknown }).code : undefined;
  return typeof code === 'string' && KNOWN.has(code) ? (code as ClubErrorCode) : null;
}

/** The i18n key for a caught club error, or null when it carries no known code. */
export function clubErrorKey(e: unknown): string | null {
  const code = clubErrorCode(e);
  return code ? `clubs.errors.${code}` : null;
}

export function hasClubErrorCode(e: unknown, code: ClubErrorCode): boolean {
  return clubErrorCode(e) === code;
}

/** Normal play of a pack linked to a running competition is blocked (409). */
export function isPackReservedError(e: unknown): boolean {
  return hasClubErrorCode(e, 'pack_reserved_for_competition');
}

/**
 * The club is gone for this player (left/removed, unknown id, or Clubs
 * switched off): the screen should offer a way back to My Clubs.
 */
export function isClubGoneError(e: unknown): boolean {
  const code = clubErrorCode(e);
  if (code === 'club_not_found' || code === 'feature_disabled') return true;
  return !code && !!e && typeof e === 'object' && (e as { status?: unknown }).status === 404;
}

/**
 * Start of a competition attempt with a number that is no longer the next one
 * (the player started an attempt on another device, or the screen is stale):
 * the backend answers a plain 422 validation error on `attempt_number`
 * (docs/api-contract.md, competition start). The screen reloads the pack
 * state and shows translated copy instead of the English server sentence.
 */
export function isStaleAttemptNumberError(e: unknown): boolean {
  if (!e || typeof e !== 'object' || (e as { status?: unknown }).status !== 422) return false;
  const errors = (e as { errors?: unknown }).errors;
  return !!errors && typeof errors === 'object' && 'attempt_number' in (errors as Record<string, unknown>);
}

/**
 * One sentence for any failed club call. Known codes → translated copy;
 * a plain 429 keeps the client's "try again in N seconds"; everything else →
 * the usual network/server/validation mapping with `fallback`.
 */
export function getClubErrorMessage(e: unknown, fallback: string): string {
  const key = clubErrorKey(e);
  if (key) return translate(key);
  if (isStaleAttemptNumberError(e)) return translate('clubs.errors.competition_attempt_stale');
  return getApiErrorMessage(e, fallback);
}
