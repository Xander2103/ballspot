import {
  registerTranslations,
  translate,
  setLocale,
  getLocale,
  subscribe,
  resolveLocale,
  hasTranslation,
  flattenKeys,
  resetLocaleForTests,
  setPersistHandler,
} from '../core';
import { en } from '../locales/en';
import { nl } from '../locales/nl';
import { fr } from '../locales/fr';
import { de } from '../locales/de';
import { es } from '../locales/es';
import { SUPPORTED_LANGUAGES, type LanguageCode } from '../../utils/language';

const ALL = { en, nl, fr, de, es };

beforeEach(() => {
  registerTranslations(ALL, 'en');
  resetLocaleForTests();
  setPersistHandler(null);
});

describe('translation lookup', () => {
  it('resolves a key in every supported language', () => {
    for (const { code } of SUPPORTED_LANGUAGES) {
      const value = translate('common.buttons.save', undefined, code);
      expect(typeof value).toBe('string');
      expect(value).not.toBe('common.buttons.save');
      expect(value.trim().length).toBeGreaterThan(0);
    }
  });

  it('every language has every English key (no silent English fallback)', () => {
    const keys = flattenKeys(en);
    expect(keys.length).toBeGreaterThan(50);
    for (const { code } of SUPPORTED_LANGUAGES) {
      const missing = keys.filter((k) => !hasTranslation(k, code));
      expect({ locale: code, missing }).toEqual({ locale: code, missing: [] });
    }
  });

  it('non-English languages actually differ from English for common buttons', () => {
    for (const code of ['nl', 'fr', 'de', 'es'] as LanguageCode[]) {
      expect(translate('common.buttons.cancel', undefined, code)).not.toBe(en.common.buttons.cancel);
    }
  });

  it('falls back to English, then to the key, for missing keys', () => {
    registerTranslations({ en, nl: { common: { buttons: { save: 'Opslaan' } } } }, 'en');
    setLocale('nl');
    expect(translate('common.buttons.save')).toBe('Opslaan');
    expect(translate('common.buttons.cancel')).toBe('Cancel');
    expect(translate('does.not.exist')).toBe('does.not.exist');
    expect(translate('common')).toBe('common'); // a branch, not a leaf
  });

  it('interpolates {{params}} and leaves unknown placeholders visible', () => {
    expect(translate('errors.rateLimited', { seconds: 30 })).toBe('Too many attempts. Try again in 30 seconds.');
    expect(translate('common.time.endsIn', {})).toBe('Ends in {{time}}');
  });

  it('picks singular/plural forms from count', () => {
    expect(translate('common.time.minutes', { count: 1 })).toBe('1 minute');
    expect(translate('common.time.minutes', { count: 5 })).toBe('5 minutes');
    expect(translate('common.time.minutes', { count: 0 })).toBe('0 minutes');
  });

  it('keeps every interpolation placeholder in every translation', () => {
    // A translator dropping {{count}} would render "Try again in seconds" —
    // catch it here rather than on a device.
    const placeholders = (s: string) => (s.match(/\{\{\s*\w+\s*\}\}/g) ?? []).map((p) => p.replace(/\s/g, '')).sort();
    for (const key of flattenKeys(en)) {
      const expected = placeholders(translate(key, undefined, 'en'));
      if (!expected.length) continue;
      for (const code of ['nl', 'fr', 'de', 'es'] as LanguageCode[]) {
        expect({ key, code, placeholders: placeholders(translate(key, undefined, code)) })
          .toEqual({ key, code, placeholders: expected });
      }
    }
  });
});

describe('locale store', () => {
  it('ignores unsupported values and notifies subscribers on change', () => {
    const seen: string[] = [];
    const unsubscribe = subscribe((l) => seen.push(l));
    expect(setLocale('xx')).toBe('en');
    expect(setLocale('fr')).toBe('fr');
    expect(setLocale('fr')).toBe('fr'); // no duplicate notification
    unsubscribe();
    setLocale('de');
    expect(seen).toEqual(['fr']);
    expect(getLocale()).toBe('de');
  });

  it('persists through the registered handler', () => {
    const persisted: string[] = [];
    setPersistHandler((l) => persisted.push(l));
    setLocale('es');
    setLocale('nl', { persist: false });
    expect(persisted).toEqual(['es']);
  });
});

describe('resolveLocale fallback order', () => {
  it('user preference > stored choice > device > config default > en', () => {
    expect(resolveLocale({ userPreferred: 'de', storedChoice: 'fr', deviceLocale: 'nl-BE', configDefault: 'es' })).toBe('de');
    expect(resolveLocale({ userPreferred: null, storedChoice: 'fr', deviceLocale: 'nl-BE', configDefault: 'es' })).toBe('fr');
    expect(resolveLocale({ storedChoice: null, deviceLocale: 'nl-BE', configDefault: 'es' })).toBe('nl');
    expect(resolveLocale({ deviceLocale: 'it-IT', configDefault: 'es' })).toBe('es');
    expect(resolveLocale({ deviceLocale: 'it-IT', configDefault: 'zz' })).toBe('en');
    expect(resolveLocale({})).toBe('en');
  });

  it('never trusts an unsupported user/stored value', () => {
    expect(resolveLocale({ userPreferred: 'zz', storedChoice: 'EN', deviceLocale: 'de' })).toBe('de');
  });
});
