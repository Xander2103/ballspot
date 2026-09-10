/**
 * BallPicker i18n core — pure TypeScript, no React Native imports, so it runs
 * under the plain ts-jest runner and can be used from non-React code (API
 * client, error mappers) as well as from components (see ./index.ts).
 *
 * Design:
 *  - Translations are nested objects per language (locales/*). English is the
 *    source of truth: `Translations = typeof en`, so every other language is
 *    type-checked for completeness by `tsc --noEmit`.
 *  - Lookup: active locale → English → the key itself. A missing key never
 *    throws and never renders as empty text.
 *  - Interpolation: `{{name}}` placeholders. Plurals: `t('key', { count })`
 *    picks `key_one` / `key_other` when they exist (all five languages use the
 *    one/other split for the strings we have).
 *  - A tiny observable store holds the active locale so a change from Profile
 *    (or the register picker) re-renders every mounted screen immediately.
 */

import { DEFAULT_LANGUAGE, isLanguageCode, resolveLanguageFromLocale, type LanguageCode } from '../utils/language';
import { en } from './locales/en';

export type TranslationTree = { [key: string]: string | TranslationTree };

export type TranslateParams = Record<string, string | number | null | undefined>;

type Listener = (locale: LanguageCode) => void;

const listeners = new Set<Listener>();
let activeLocale: LanguageCode = DEFAULT_LANGUAGE;
// English is registered up front so `translate()` is meaningful for any
// module that imports the core directly (error mappers, tests) — ./index.ts
// registers the other four languages at app start.
let tables: Partial<Record<LanguageCode, TranslationTree>> = { en };
let fallbackTable: TranslationTree = en;
/** Optional hook so the app can persist locale changes (set by ./index.ts). */
let persist: ((locale: LanguageCode) => void) | null = null;

/** Register the translation tables (called once at module load by ./index.ts). */
export function registerTranslations(all: Partial<Record<LanguageCode, TranslationTree>>, fallback: LanguageCode = DEFAULT_LANGUAGE): void {
  tables = all;
  fallbackTable = all[fallback] ?? {};
}

export function setPersistHandler(handler: ((locale: LanguageCode) => void) | null): void {
  persist = handler;
}

export function getLocale(): LanguageCode {
  return activeLocale;
}

/**
 * Switch the active language. Unsupported values are ignored (never crash the
 * UI on a bad stored value). Listeners are only notified on a real change.
 */
export function setLocale(locale: string | null | undefined, options: { persist?: boolean } = {}): LanguageCode {
  if (!isLanguageCode(locale)) return activeLocale;
  const changed = locale !== activeLocale;
  activeLocale = locale;
  if (options.persist !== false && persist) {
    try { persist(locale); } catch { /* best effort */ }
  }
  if (changed) listeners.forEach((l) => { try { l(locale); } catch { /* one bad listener must not break the rest */ } });
  return activeLocale;
}

export function subscribe(listener: Listener): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

/** Test helper: reset to the default language without persisting. */
export function resetLocaleForTests(): void {
  activeLocale = DEFAULT_LANGUAGE;
}

function lookup(tree: TranslationTree | undefined, key: string): string | undefined {
  if (!tree) return undefined;
  let node: string | TranslationTree | undefined = tree;
  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined;
    node = node[part];
  }
  return typeof node === 'string' ? node : undefined;
}

function interpolate(template: string, params?: TranslateParams): string {
  if (!params) return template;
  return template.replace(/\{\{\s*(\w+)\s*\}\}/g, (match, name: string) => {
    const value = params[name];
    return value === null || value === undefined ? match : String(value);
  });
}

/**
 * Translate `key` in `locale` (defaults to the active one). Fallback chain:
 * requested locale → English → the key itself.
 */
export function translate(key: string, params?: TranslateParams, locale: LanguageCode = activeLocale): string {
  const table = tables[locale];
  const wantsPlural = params && typeof params.count === 'number';
  const pluralKey = wantsPlural ? `${key}_${params!.count === 1 ? 'one' : 'other'}` : null;

  const candidates = pluralKey ? [pluralKey, key] : [key];
  for (const source of [table, fallbackTable]) {
    for (const candidate of candidates) {
      const found = lookup(source, candidate);
      if (found !== undefined) return interpolate(found, params);
    }
  }
  return key;
}

/** True when `key` exists in the given locale (no fallback). Used by tests. */
export function hasTranslation(key: string, locale: LanguageCode): boolean {
  return lookup(tables[locale], key) !== undefined;
}

/** Flat list of every dotted key in a tree (tests + missing-key audits). */
export function flattenKeys(tree: TranslationTree, prefix = ''): string[] {
  const out: string[] = [];
  for (const [k, v] of Object.entries(tree)) {
    const full = prefix ? `${prefix}.${k}` : k;
    if (typeof v === 'string') out.push(full);
    else out.push(...flattenKeys(v, full));
  }
  return out;
}

export interface LocaleSources {
  /** users.preferred_language from the API (signed-in user). */
  userPreferred?: string | null;
  /** The language the user picked on the register screen / last stored choice. */
  storedChoice?: string | null;
  /** BCP-47 device locale, e.g. "nl-BE". */
  deviceLocale?: string | null;
  /** BALLPICKER_DEFAULT_LANGUAGE from GET /api/config. */
  configDefault?: string | null;
}

/**
 * The fallback order from the spec:
 *   1. user preferred_language  2. register-selected/stored language
 *   3. device language if supported  4. BALLPICKER_DEFAULT_LANGUAGE  5. en
 */
export function resolveLocale(sources: LocaleSources): LanguageCode {
  if (isLanguageCode(sources.userPreferred)) return sources.userPreferred;
  if (isLanguageCode(sources.storedChoice)) return sources.storedChoice;
  const device = (sources.deviceLocale ?? '').trim();
  if (device) {
    const fromDevice = resolveLanguageFromLocale(device, 'en');
    // resolveLanguageFromLocale returns the fallback for unsupported locales;
    // only accept it when the device really asked for a supported language.
    if (isLanguageCode(device.toLowerCase().split(/[-_]/)[0])) return fromDevice;
  }
  if (isLanguageCode(sources.configDefault)) return sources.configDefault;
  return DEFAULT_LANGUAGE;
}
