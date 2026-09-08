import {
  DEFAULT_LANGUAGE,
  SUPPORTED_LANGUAGES,
  isLanguageCode,
  languageLabel,
  resolveLanguageFromLocale,
  readDeviceLocale,
  defaultLanguageForDevice,
} from '../language';

describe('language', () => {
  it('lists exactly the five supported languages with their native labels', () => {
    expect(SUPPORTED_LANGUAGES.map((l) => l.code)).toEqual(['nl', 'en', 'fr', 'de', 'es']);
    expect(SUPPORTED_LANGUAGES.map((l) => l.label)).toEqual(['Nederlands', 'English', 'Français', 'Deutsch', 'Español']);
  });

  it('resolves device locales to a supported code', () => {
    expect(resolveLanguageFromLocale('nl-BE')).toBe('nl');
    expect(resolveLanguageFromLocale('fr_FR')).toBe('fr');
    expect(resolveLanguageFromLocale('DE')).toBe('de');
    expect(resolveLanguageFromLocale('es-419')).toBe('es');
    expect(resolveLanguageFromLocale('en-US')).toBe('en');
  });

  it('falls back to English for unsupported or missing locales', () => {
    expect(resolveLanguageFromLocale('it-IT')).toBe(DEFAULT_LANGUAGE);
    expect(resolveLanguageFromLocale('')).toBe('en');
    expect(resolveLanguageFromLocale(null)).toBe('en');
    expect(resolveLanguageFromLocale(undefined)).toBe('en');
    expect(resolveLanguageFromLocale('pt-BR', 'nl')).toBe('nl');
  });

  it('validates codes and labels', () => {
    expect(isLanguageCode('nl')).toBe(true);
    expect(isLanguageCode('NL')).toBe(false);
    expect(isLanguageCode(42)).toBe(false);
    expect(languageLabel('fr')).toBe('Français');
    expect(languageLabel('zz')).toBe('English');
    expect(languageLabel(null)).toBe('English');
  });

  it('reads the device locale without throwing and always yields a supported code', () => {
    const locale = readDeviceLocale();
    expect(locale === null || typeof locale === 'string').toBe(true);
    expect(isLanguageCode(defaultLanguageForDevice())).toBe(true);
  });
});
