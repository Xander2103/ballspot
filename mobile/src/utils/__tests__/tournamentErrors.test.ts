import {
  TOURNAMENTS_UNAVAILABLE_CODE,
  getTournamentErrorMessage,
  isTournamentsUnavailable,
  tournamentsUnavailableCopy,
} from '../tournamentErrors';
import { registerTranslations, resetLocaleForTests, setLocale } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';

beforeEach(() => {
  registerTranslations({ en, nl }, 'en');
  resetLocaleForTests();
});

const unavailable = {
  status: 422,
  message: 'Tournaments are temporarily unavailable while we prepare new challenges. Please try again soon.',
  code: TOURNAMENTS_UNAVAILABLE_CODE,
  reason: 'INSUFFICIENT_TOURNAMENT_CHALLENGES',
  required: 7,
  available: 0,
};

describe('tournament unavailable mapping', () => {
  it('recognises the structured code and nothing else', () => {
    expect(isTournamentsUnavailable(unavailable)).toBe(true);
    expect(isTournamentsUnavailable({ status: 422, message: 'Not enough' })).toBe(false);
    expect(isTournamentsUnavailable(null)).toBe(false);
    expect(isTournamentsUnavailable('x')).toBe(false);
  });

  it('maps the code to friendly translated copy, never the raw 422 text', () => {
    const msg = getTournamentErrorMessage(unavailable, 'fallback');
    expect(msg).toBe(en.tournaments.unavailable.body);
    expect(msg).not.toMatch(/422|INSUFFICIENT|Not enough/);
    expect(tournamentsUnavailableCopy().title).toBe('Tournaments temporarily unavailable');

    setLocale('nl');
    expect(getTournamentErrorMessage(unavailable, 'fallback')).toBe(nl.tournaments.unavailable.body);
    expect(nl.tournaments.unavailable.body).toMatch(/Toernooien zijn tijdelijk niet beschikbaar/);
  });

  it('falls through to the generic API mapping for other errors', () => {
    expect(getTournamentErrorMessage({ status: 422, message: 'You can only host one active tournament at a time.' }, 'f'))
      .toBe('You can only host one active tournament at a time.');
    expect(getTournamentErrorMessage({ status: 500, message: 'Server Error' }, 'f')).toBe(en.errors.server);
    expect(getTournamentErrorMessage(new TypeError('Network request failed'), 'f')).toBe(en.errors.network);
  });
});
