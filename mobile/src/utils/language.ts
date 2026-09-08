/**
 * Preferred-language support (nl | en | fr | de | es).
 *
 * Mirrors backend config `ballspot.languages`. The device locale is read via
 * Intl (available on Hermes for both platforms and on web) inside a try/catch
 * — reading it must never crash the register screen. Unsupported or
 * unreadable → English, the language of the app copy and emails today.
 */

export type LanguageCode = 'nl' | 'en' | 'fr' | 'de' | 'es';

export const SUPPORTED_LANGUAGES: { code: LanguageCode; label: string }[] = [
  { code: 'nl', label: 'Nederlands' },
  { code: 'en', label: 'English' },
  { code: 'fr', label: 'Français' },
  { code: 'de', label: 'Deutsch' },
  { code: 'es', label: 'Español' },
];

export const DEFAULT_LANGUAGE: LanguageCode = 'en';

export function isLanguageCode(value: unknown): value is LanguageCode {
  return typeof value === 'string' && SUPPORTED_LANGUAGES.some((l) => l.code === value);
}

export function languageLabel(code: string | null | undefined): string {
  return SUPPORTED_LANGUAGES.find((l) => l.code === code)?.label ?? SUPPORTED_LANGUAGES.find((l) => l.code === DEFAULT_LANGUAGE)!.label;
}

/**
 * Turn a BCP-47 locale ("nl-BE", "fr_FR", "de", "EN-us") into a supported
 * code, or the default when it is missing/unsupported.
 */
export function resolveLanguageFromLocale(locale: string | null | undefined, fallback: LanguageCode = DEFAULT_LANGUAGE): LanguageCode {
  const primary = (locale ?? '').trim().toLowerCase().split(/[-_]/)[0];
  return isLanguageCode(primary) ? primary : fallback;
}

/** Best-effort device locale; null when it cannot be read safely. */
export function readDeviceLocale(): string | null {
  try {
    const intl = (globalThis as { Intl?: { DateTimeFormat?: () => { resolvedOptions: () => { locale?: string } } } }).Intl;
    const locale = intl?.DateTimeFormat?.().resolvedOptions().locale;
    return typeof locale === 'string' && locale.trim() ? locale : null;
  } catch {
    return null;
  }
}

/** What the register screen preselects. */
export function defaultLanguageForDevice(): LanguageCode {
  return resolveLanguageFromLocale(readDeviceLocale());
}
