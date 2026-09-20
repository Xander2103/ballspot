/**
 * Admin-managed Home / Daily-card notice — pure TypeScript (see
 * __tests__/notice.test.ts). The backend already returns ONE localized
 * `message`; this module makes the Home screen robust to whatever it gets:
 * a null notice, an unknown type, an empty message, or an older/newer body
 * that carries a per-language `messages` map instead.
 */

import type { AppNotice, NoticeType, NoticePlacement } from '../types/notice';

export const NOTICE_TYPES: readonly NoticeType[] = ['info', 'warning', 'success'];
export const NOTICE_PLACEMENTS: readonly NoticePlacement[] = ['home_daily_card'];

function asRecord(v: unknown): Record<string, unknown> | null {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : null;
}

/**
 * Pick the message for `locale` from a per-language map: requested language
 * → English → the first non-empty one. Null when nothing is filled in.
 */
export function pickNoticeMessage(messages: unknown, locale: string): string | null {
  const map = asRecord(messages);
  if (!map) return null;
  const clean = (v: unknown) => (typeof v === 'string' && v.trim() ? v.trim() : null);
  return clean(map[locale]) ?? clean(map.en) ?? Object.values(map).map(clean).find((m) => m !== null) ?? null;
}

/**
 * Normalize an API body (`{ notice }`, a bare notice, or null) into the
 * notice the Home screen renders — or null, in which case Home renders
 * nothing at all (no empty card, no gap).
 */
export function resolveNotice(raw: unknown, locale = 'en'): AppNotice | null {
  const outer = asRecord(raw);
  const inner = outer && 'notice' in outer ? asRecord(outer.notice) : outer;
  if (!inner) return null;

  const message =
    (typeof inner.message === 'string' && inner.message.trim() ? inner.message.trim() : null)
    ?? pickNoticeMessage(inner.messages, locale);
  if (!message) return null;

  const placement = typeof inner.placement === 'string' && (NOTICE_PLACEMENTS as readonly string[]).includes(inner.placement)
    ? (inner.placement as NoticePlacement)
    : 'home_daily_card';
  const type = typeof inner.type === 'string' && (NOTICE_TYPES as readonly string[]).includes(inner.type)
    ? (inner.type as NoticeType)
    : 'info';

  return { placement, type, message };
}

export interface NoticeThemeSlice {
  accent: string;
  warning: string;
  success: string;
  text: string;
}

export interface NoticePalette {
  /** Border + glyph colour, from the theme so every theme stays coherent. */
  accent: string;
  /** Leading glyph — a small, quiet marker rather than a big icon. */
  glyph: string;
  /** Accessibility role label for screen readers. */
  a11yType: NoticeType;
}

/** info → theme accent (neutral), warning → theme.warning, success → theme.success. */
export function noticePalette(type: NoticeType, theme: NoticeThemeSlice): NoticePalette {
  if (type === 'warning') return { accent: theme.warning, glyph: '⚠️', a11yType: 'warning' };
  if (type === 'success') return { accent: theme.success, glyph: '✅', a11yType: 'success' };
  return { accent: theme.accent, glyph: 'ℹ️', a11yType: 'info' };
}
