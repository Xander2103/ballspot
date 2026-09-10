/**
 * React-facing i18n API. Import `t` for non-component code and `useI18n()`
 * inside components (it re-renders on language change).
 *
 *   const { t } = useI18n();
 *   <Text>{t('common.buttons.save')}</Text>
 *   t('common.time.minutes', { count: 3 })       // plural
 *   t('errors.rateLimited', { seconds: 30 })     // interpolation
 */
import { useCallback, useSyncExternalStore } from 'react';
import {
  registerTranslations,
  translate,
  getLocale,
  setLocale,
  subscribe,
  setPersistHandler,
  resolveLocale,
  type TranslateParams,
} from './core';
import { en } from './locales/en';
import { nl } from './locales/nl';
import { fr } from './locales/fr';
import { de } from './locales/de';
import { es } from './locales/es';
import { localFlags } from '../storage/localFlags';
import { configApi } from '../api/configApi';
import { isLanguageCode, readDeviceLocale, type LanguageCode } from '../utils/language';

export { getLocale, setLocale, subscribe, resolveLocale };
export type { TranslateParams };

/** Non-secret device-level flag: the last language chosen on this device. */
export const LANGUAGE_STORAGE_KEY = 'ballpicker_language';
const CONFIG_DEFAULT_KEY = 'ballpicker_config_default_language';

registerTranslations({ en, nl, fr, de, es }, 'en');
setPersistHandler((locale) => { void localFlags.set(LANGUAGE_STORAGE_KEY, locale); });

/** Translate in the active language (for code outside React). */
export function t(key: string, params?: TranslateParams): string {
  return translate(key, params);
}

/** Hook: current locale + a `t` bound to it. Re-renders on language change. */
export function useI18n() {
  const locale = useSyncExternalStore(subscribe, getLocale, getLocale);
  const boundT = useCallback((key: string, params?: TranslateParams) => translate(key, params, locale), [locale]);
  return { t: boundT, locale, setLocale };
}

/**
 * Resolve and apply the startup language. Cheap and offline-safe: the stored
 * choice and the device locale need no network; the backend default is only
 * fetched (best effort, short timeout) when neither of those resolved, and is
 * cached for the next launch.
 */
export async function initLocale(userPreferred?: string | null): Promise<LanguageCode> {
  const storedChoice = await localFlags.get(LANGUAGE_STORAGE_KEY);
  const deviceLocale = readDeviceLocale();
  let configDefault = await localFlags.get(CONFIG_DEFAULT_KEY);

  const provisional = resolveLocale({ userPreferred, storedChoice, deviceLocale, configDefault });
  // Only ask the backend for its default when nothing above it in the
  // fallback order resolved to a SUPPORTED language (an Italian device with
  // no stored choice must still pick up BALLPICKER_DEFAULT_LANGUAGE=nl).
  const deviceSupported = isLanguageCode((deviceLocale ?? '').trim().toLowerCase().split(/[-_]/)[0]);
  const needsConfig = !isLanguageCode(userPreferred) && !isLanguageCode(storedChoice) && !deviceSupported && !isLanguageCode(configDefault);
  if (needsConfig) {
    try {
      const cfg = await Promise.race([
        configApi.get(),
        new Promise<null>((resolve) => setTimeout(() => resolve(null), 1500)),
      ]);
      if (cfg?.default_language) {
        configDefault = cfg.default_language;
        void localFlags.set(CONFIG_DEFAULT_KEY, configDefault);
      }
    } catch {
      // offline — fall through to the built-in default
    }
  }

  const resolved = needsConfig
    ? resolveLocale({ userPreferred, storedChoice, deviceLocale, configDefault })
    : provisional;
  setLocale(resolved, { persist: true });
  return resolved;
}

/** Remember the backend default language whenever /api/config is fetched. */
export function rememberConfigDefault(defaultLanguage: string | undefined | null): void {
  if (defaultLanguage) void localFlags.set(CONFIG_DEFAULT_KEY, defaultLanguage);
}
