/**
 * The object the player is looking for, per sport. Drives the guess marker
 * (ghost marker on the guess screens, the fullscreen viewer, the result
 * legend) so a tennis challenge inside a Mixed Sports pack shows a tennis
 * ball rather than a football.
 *
 * Always keyed off the CHALLENGE's sport, never the pack's or tournament's
 * (a "Mixed Sports" pack has no single sport). Unknown/missing → football,
 * which is what every screen showed before this helper existed.
 */

import { translate } from '../i18n/core';

export type SportObjectKey =
  | 'football'
  | 'basketball'
  | 'tennis'
  | 'padel'
  | 'golf'
  | 'hockey'
  | 'baseball'
  | 'volleyball'
  | 'american_football'
  | 'cricket'
  | 'table_tennis';

export const DEFAULT_SPORT_OBJECT: SportObjectKey = 'football';

const ICONS: Record<SportObjectKey, string> = {
  football: '⚽',
  basketball: '🏀',
  tennis: '🎾',
  padel: '🎾',
  golf: '⛳',
  hockey: '🏒',
  baseball: '⚾',
  volleyball: '🏐',
  american_football: '🏈',
  cricket: '🏏',
  table_tennis: '🏓',
};

/** Slug spellings the backend/admin may use → canonical key. */
const ALIASES: Record<string, SportObjectKey> = {
  soccer: 'football',
  'american-football': 'american_football',
  americanfootball: 'american_football',
  'table-tennis': 'table_tennis',
  tabletennis: 'table_tennis',
  'ping-pong': 'table_tennis',
  pingpong: 'table_tennis',
  'ice-hockey': 'hockey',
  ice_hockey: 'hockey',
  'field-hockey': 'hockey',
  field_hockey: 'hockey',
};

/** Normalise a sport slug/name to a known object key, or the default. */
export function resolveSportObject(sportSlug: string | null | undefined): SportObjectKey {
  const raw = (sportSlug ?? '').trim().toLowerCase();
  if (!raw) return DEFAULT_SPORT_OBJECT;
  if (raw in ICONS) return raw as SportObjectKey;
  if (raw in ALIASES) return ALIASES[raw];
  const underscored = raw.replace(/[\s-]+/g, '_');
  if (underscored in ICONS) return underscored as SportObjectKey;
  if (underscored in ALIASES) return ALIASES[underscored];
  return DEFAULT_SPORT_OBJECT;
}

/** Emoji used for the guess marker. */
export function getSportObjectIcon(sportSlug: string | null | undefined): string {
  return ICONS[resolveSportObject(sportSlug)];
}

/** Translated noun for the object ("tennis ball", "puck", …), in the active language. */
export function getSportObjectLabel(sportSlug: string | null | undefined): string {
  return translate(`game.sportObject.${resolveSportObject(sportSlug)}`);
}

/**
 * Per-object marker tweaks. Only the golf flag glyph renders visibly
 * off-centre at marker size, so it gets a small optical nudge; everything
 * else keeps the existing marker look.
 */
export function getSportObjectMarkerStyle(sportSlug: string | null | undefined): { fontSize?: number; marginTop?: number } {
  switch (resolveSportObject(sportSlug)) {
    case 'golf':
      return { marginTop: -2 };
    default:
      return {};
  }
}
