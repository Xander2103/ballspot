/**
 * Presentation helpers for club cards and headers (pure, unit tested).
 * Everything user-visible goes through the i18n core.
 */
import { translate } from '../i18n/core';
import type { LanguageCode } from './language';
import type { ClubCard, ClubPackCard, ClubRole, CompetitionCard } from '../types/club';

const ROLE_KEYS: Record<ClubRole, string> = {
  owner: 'clubs.roles.owner',
  admin: 'clubs.roles.admin',
  member: 'clubs.roles.member',
};

export function roleLabel(role: string | null | undefined, locale?: LanguageCode): string {
  const key = role && role in ROLE_KEYS ? ROLE_KEYS[role as ClubRole] : ROLE_KEYS.member;
  return translate(key, undefined, locale);
}

const CLUB_TYPES = ['sports_club', 'school', 'company', 'community', 'other'];

export function clubTypeLabel(type: string | null | undefined, locale?: LanguageCode): string | null {
  if (!type || !CLUB_TYPES.includes(type)) return null;
  return translate(`clubs.types.${type}`, undefined, locale);
}

/**
 * The card's second line: "Gent · 6 packs · 1 live · 2 upcoming". Zero counts
 * for competitions are left out; the pack count is always shown.
 */
export function clubMetaParts(club: Pick<ClubCard, 'city' | 'packs_count' | 'competitions'>, locale?: LanguageCode): string[] {
  const parts: string[] = [];
  if (club.city) parts.push(club.city);
  parts.push(translate('clubs.card.packs', { count: club.packs_count ?? 0 }, locale));
  const active = club.competitions?.active ?? 0;
  const upcoming = club.competitions?.upcoming ?? 0;
  if (active > 0) parts.push(translate('clubs.card.live', { count: active }, locale));
  if (upcoming > 0) parts.push(translate('clubs.card.upcoming', { count: upcoming }, locale));
  return parts;
}

/** Header location line: "Gent, BE · Sports club · 214 members". */
export function clubHeaderParts(
  club: { city: string | null; country_code: string | null; club_type: string; members_count: number },
  locale?: LanguageCode,
): string[] {
  const parts: string[] = [];
  const place = [club.city, club.country_code].filter(Boolean).join(', ');
  if (place) parts.push(place);
  const type = clubTypeLabel(club.club_type, locale);
  if (type) parts.push(type);
  parts.push(translate('clubs.detail.members', { count: club.members_count ?? 0 }, locale));
  return parts;
}

/** Locale tag for Date formatting (English uses day-month order like the rest of the app). */
/** Compact club-homepage line: "Gent, BE · 214 members" (no club type — the header stays short). */
export function clubDetailParts(
  club: { city: string | null; country_code: string | null; members_count: number },
  locale?: LanguageCode,
): string[] {
  const parts: string[] = [];
  const place = [club.city, club.country_code].filter(Boolean).join(', ');
  if (place) parts.push(place);
  parts.push(translate('clubs.detail.members', { count: club.members_count ?? 0 }, locale));
  return parts;
}

export function dateLocaleTag(locale: LanguageCode | string | undefined): string {
  if (!locale || locale === 'en') return 'en-GB';
  return locale;
}

/** "1 Nov, 10:00" in the device time zone; '' for an unreadable date. */
export function formatClubDateTime(iso: string | null | undefined, locale?: LanguageCode | string): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  try {
    return date.toLocaleString(dateLocaleTag(locale), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  } catch {
    return date.toISOString().slice(0, 16).replace('T', ' ');
  }
}

/** "1 Nov 2026"; '' for an unreadable date. */
export function formatClubDate(iso: string | null | undefined, locale?: LanguageCode | string): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  try {
    return date.toLocaleDateString(dateLocaleTag(locale), { day: 'numeric', month: 'short', year: 'numeric' });
  } catch {
    return date.toISOString().slice(0, 10);
  }
}

export type CompetitionSegment = 'active' | 'upcoming' | 'completed';

/** The line under a competition's name in the club detail list. */
export function competitionSubtitle(c: CompetitionCard, locale?: LanguageCode): string {
  const parts: string[] = [];
  if (c.phase === 'upcoming') {
    parts.push(translate('clubs.competitions.startsAt', { date: formatClubDateTime(c.starts_at, locale) }, locale));
  } else if (c.phase === 'active') {
    parts.push(translate('clubs.competitions.endsAt', { date: formatClubDateTime(c.ends_at, locale) }, locale));
  } else {
    parts.push(translate('clubs.competitions.endedAt', { date: formatClubDateTime(c.ends_at, locale) }, locale));
  }
  if (c.me && c.me.rank > 0 && c.phase !== 'upcoming') {
    parts.push(translate('clubs.competitions.yourRank', { rank: c.me.rank, total: c.participants_count }, locale));
  } else {
    parts.push(translate('clubs.competitions.players', { count: c.participants_count ?? 0 }, locale));
  }
  return parts.join(' · ');
}

export type ClubPackState = 'reserved' | 'completed' | 'active' | 'new';

/** Which status chip a club pack row shows. "Competition only" wins over progress. */
export function clubPackState(pack: Pick<ClubPackCard, 'reserved_for_competition' | 'progress'>): ClubPackState {
  if (pack.reserved_for_competition) return 'reserved';
  if (pack.progress?.status === 'completed') return 'completed';
  if (pack.progress?.status === 'active') return 'active';
  return 'new';
}
