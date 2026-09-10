import {
  DEFAULT_SPORT_OBJECT,
  getSportObjectIcon,
  getSportObjectLabel,
  getSportObjectMarkerStyle,
  resolveSportObject,
} from '../sportObject';
import { registerTranslations, resetLocaleForTests, setLocale } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';

beforeEach(() => {
  registerTranslations({ en, nl }, 'en');
  resetLocaleForTests();
});

describe('getSportObjectIcon', () => {
  it('maps every supported sport to its object', () => {
    expect(getSportObjectIcon('football')).toBe('⚽');
    expect(getSportObjectIcon('basketball')).toBe('🏀');
    expect(getSportObjectIcon('tennis')).toBe('🎾');
    expect(getSportObjectIcon('padel')).toBe('🎾');
    expect(getSportObjectIcon('golf')).toBe('⛳');
    expect(getSportObjectIcon('hockey')).toBe('🏒');
    expect(getSportObjectIcon('baseball')).toBe('⚾');
    expect(getSportObjectIcon('volleyball')).toBe('🏐');
    expect(getSportObjectIcon('american_football')).toBe('🏈');
    expect(getSportObjectIcon('cricket')).toBe('🏏');
    expect(getSportObjectIcon('table_tennis')).toBe('🏓');
  });

  it('accepts alias spellings used by the backend/admin', () => {
    expect(getSportObjectIcon('american-football')).toBe('🏈');
    expect(getSportObjectIcon('tabletennis')).toBe('🏓');
    expect(getSportObjectIcon('table-tennis')).toBe('🏓');
    expect(getSportObjectIcon('Ice Hockey')).toBe('🏒');
    expect(getSportObjectIcon('SOCCER')).toBe('⚽');
  });

  it('falls back to football for unknown or missing sport data', () => {
    expect(getSportObjectIcon('curling')).toBe('⚽');
    expect(getSportObjectIcon('')).toBe('⚽');
    expect(getSportObjectIcon(null)).toBe('⚽');
    expect(getSportObjectIcon(undefined)).toBe('⚽');
    expect(resolveSportObject('???')).toBe(DEFAULT_SPORT_OBJECT);
  });
});

describe('getSportObjectLabel', () => {
  it('names the object in the active language', () => {
    expect(getSportObjectLabel('tennis')).toBe('tennis ball');
    expect(getSportObjectLabel('hockey')).toBe('puck');
    expect(getSportObjectLabel(null)).toBe('football');
    setLocale('nl');
    expect(getSportObjectLabel('hockey')).not.toBe('puck-en-placeholder');
    expect(getSportObjectLabel('hockey')).toBe(nl.game.sportObject.hockey);
  });
});

describe('getSportObjectMarkerStyle', () => {
  it('only nudges the golf glyph; everything else keeps the default marker', () => {
    expect(getSportObjectMarkerStyle('golf')).toEqual({ marginTop: -2 });
    expect(getSportObjectMarkerStyle('football')).toEqual({});
    expect(getSportObjectMarkerStyle(undefined)).toEqual({});
  });
});
