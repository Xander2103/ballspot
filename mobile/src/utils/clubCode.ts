/**
 * Club invite codes (pure, unit tested).
 *
 * Backend contract (ClubInviteCodeService): 8 characters from the alphabet
 * ABCDEFGHJKLMNPQRSTUVWXYZ23456789 (no I, O, 0, 1), compared after upper-casing
 * and stripping spaces/dashes, displayed as ABCD-EFGH. A QR code carries
 * `ballpicker:club:CODE`.
 */

export const CLUB_CODE_LENGTH = 8;
export const CLUB_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
export const CLUB_QR_PREFIX = 'ballpicker:club:';

const VALID_CODE = new RegExp(`^[${CLUB_CODE_ALPHABET}]{${CLUB_CODE_LENGTH}}$`);
const FRIEND_QR = /^\s*ballpicker:friend:/i;

function stripQrPrefix(raw: string | null | undefined): string {
  const value = String(raw ?? '').trim();
  return value.toLowerCase().startsWith(CLUB_QR_PREFIX) ? value.slice(CLUB_QR_PREFIX.length) : value;
}

/** Upper-case and strip whitespace and dashes (same rule as the backend's normalise()). */
export function normaliseClubCode(raw: string | null | undefined): string {
  return String(raw ?? '')
    .toUpperCase()
    .replace(/[\s_\-\u2010-\u2015\u2212]+/g, '');
}

/**
 * Format for display and as-you-type input: keeps letters/digits only, caps at
 * 8 characters, and inserts the dash after the 4th ("ABCD-EF" while typing).
 */
export function formatClubCode(raw: string | null | undefined): string {
  // A pasted QR payload ("ballpicker:club:ABCD-EFGH") formats as its code.
  const compact = normaliseClubCode(stripQrPrefix(raw)).replace(/[^A-Z0-9]/g, '').slice(0, CLUB_CODE_LENGTH);
  return compact.length > 4 ? `${compact.slice(0, 4)}-${compact.slice(4)}` : compact;
}

/** True for a complete, well-formed normalised code. */
export function isValidClubCode(normalised: string): boolean {
  return VALID_CODE.test(normalised);
}

/**
 * Accepts a typed or scanned value — bare code, `ballpicker:club:CODE`, any
 * case, with dashes/spaces — and returns the normalised code, or null when it
 * cannot be a club code.
 */
export function parseClubCode(raw: string | null | undefined): string | null {
  const code = normaliseClubCode(stripQrPrefix(raw));
  return isValidClubCode(code) ? code : null;
}

/** A scanned QR that is a BallPicker FRIEND code (so the scanner can say so instead of "invalid"). */
export function isFriendQrPayload(raw: string | null | undefined): boolean {
  return FRIEND_QR.test(String(raw ?? ''));
}

/** QR payload for a code (used by future club-admin screens; mirrors the admin/portal print pages). */
export function clubQrPayload(code: string): string {
  return `${CLUB_QR_PREFIX}${normaliseClubCode(code)}`;
}
