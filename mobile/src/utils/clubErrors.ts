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
 * One sentence for any failed club call. Known codes → translated copy;
 * a plain 429 keeps the client's "try again in N seconds"; everything else →
 * the usual network/server/validation mapping with `fallback`.
 */
export function getClubErrorMessage(e: unknown, fallback: string): string {
  const key = clubErrorKey(e);
  if (key) return translate(key);
  return getApiErrorMessage(e, fallback);
}
