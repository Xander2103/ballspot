/** Club error codes → translated copy, in all five languages. */
import { registerTranslations, translate, hasTranslation } from '../../i18n/core';
import { en } from '../../i18n/locales/en';
import { nl } from '../../i18n/locales/nl';
import { fr } from '../../i18n/locales/fr';
import { de } from '../../i18n/locales/de';
import { es } from '../../i18n/locales/es';
import {
  CLUB_ERROR_CODES, clubErrorCode, clubErrorKey, getClubErrorMessage, hasClubErrorCode, isClubGoneError, isPackReservedError,
  isStaleAttemptNumberError,
} from '../clubErrors';
import { isPackAlreadyCompleted } from '../../types/pack';

beforeAll(() => registerTranslations({ en, nl, fr, de, es }, 'en'));

describe('club error mapping', () => {
  it.each(CLUB_ERROR_CODES.map((c) => [c]))('%s has copy in every language', (code) => {
    for (const locale of ['en', 'nl', 'fr', 'de', 'es'] as const) {
      expect({ code, locale, has: hasTranslation(`clubs.errors.${code}`, locale) }).toEqual({ code, locale, has: true });
    }
  });

  it('maps the join errors to translated copy, never the server sentence', () => {
    for (const code of ['club_code_invalid', 'club_join_locked', 'club_full', 'club_membership_limit'] as const) {
      const e = { status: code === 'club_join_locked' ? 429 : 422, code, message: 'raw server text' };
      expect(clubErrorKey(e)).toBe(`clubs.errors.${code}`);
      expect(getClubErrorMessage(e, 'fallback')).toBe(translate(`clubs.errors.${code}`));
      expect(getClubErrorMessage(e, 'fallback')).not.toBe('raw server text');
    }
  });

  it('a plain 429 (route throttle, no code) keeps the client "try again in N seconds" copy', () => {
    const e = { status: 429, retry_after: 30, message: translate('errors.rateLimited', { seconds: 30 }) };
    expect(clubErrorCode(e)).toBeNull();
    expect(getClubErrorMessage(e, 'fallback')).toBe(translate('errors.rateLimited', { seconds: 30 }));
  });

  it('the club-join request limiter (429 club_join_rate_limited) keeps the "try again in N seconds" copy', () => {
    // The client keeps the backend code on a 429 but replaces the message with the Retry-After copy.
    const e = { status: 429, retry_after: 42, code: 'club_join_rate_limited', message: translate('errors.rateLimited', { seconds: 42 }) };
    expect(clubErrorCode(e)).toBeNull();
    expect(getClubErrorMessage(e, 'fallback')).toBe(translate('errors.rateLimited', { seconds: 42 }));
  });

  it('a stale attempt_number (422 validation, no code) gets translated copy, not the English server sentence', () => {
    const e = { status: 422, message: 'The next attempt number is 2.', errors: { attempt_number: ['The next attempt number is 2.'] } };
    expect(isStaleAttemptNumberError(e)).toBe(true);
    expect(isStaleAttemptNumberError({ status: 422, errors: { guessed_x: ['x'] } })).toBe(false);
    expect(isStaleAttemptNumberError({ status: 409, errors: { attempt_number: ['x'] } })).toBe(false);
    expect(getClubErrorMessage(e, 'fallback')).toBe(translate('clubs.errors.competition_attempt_stale'));
    for (const locale of ['en', 'nl', 'fr', 'de', 'es'] as const) {
      expect({ locale, has: hasTranslation('clubs.errors.competition_attempt_stale', locale) }).toEqual({ locale, has: true });
    }
  });

  it('unknown codes, network and server errors fall back to the generic mapping', () => {
    expect(getClubErrorMessage({ status: 409, code: 'stale_version', message: 'Stale.' }, 'fb')).toBe('Stale.');
    expect(getClubErrorMessage(new TypeError('Network request failed'), 'fb')).toBe(translate('errors.network'));
    expect(getClubErrorMessage({ status: 500, message: 'Server Error' }, 'fb')).toBe(translate('errors.server'));
    expect(getClubErrorMessage(undefined, 'fb')).toBe('fb');
  });

  it('recognises reserved packs and gone clubs', () => {
    expect(isPackReservedError({ status: 409, code: 'pack_reserved_for_competition' })).toBe(true);
    expect(isPackReservedError({ status: 409 })).toBe(false);
    expect(isClubGoneError({ status: 404, code: 'club_not_found' })).toBe(true);
    expect(isClubGoneError({ status: 404, code: 'feature_disabled' })).toBe(true);
    expect(isClubGoneError({ status: 404 })).toBe(true);
    expect(isClubGoneError({ status: 423, code: 'club_unavailable' })).toBe(false);
    expect(hasClubErrorCode({ code: 'club_forbidden' }, 'club_forbidden')).toBe(true);
  });

  it('a reserved-pack 409 is NOT mistaken for the "already completed" replay 409', () => {
    expect(isPackAlreadyCompleted({ status: 409, code: 'pack_reserved_for_competition' })).toBe(false);
    expect(isPackAlreadyCompleted({ status: 409, code: 'competition_attempt_in_progress' })).toBe(false);
    // The public replay contract (no code) is unchanged.
    expect(isPackAlreadyCompleted({ status: 409, message: 'done', attempt: null, completion: null })).toBe(true);
    expect(isPackAlreadyCompleted({ status: 422 })).toBe(false);
  });
});
