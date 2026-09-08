import {
  AUTH_ERROR_MESSAGES,
  classifyResetError,
  getAuthErrorMessage,
  isKnownAuthCode,
  mapAuthError,
  validatePasswordPair,
} from '../authErrors';
import { SERVER_ERROR_MESSAGE } from '../apiError';

describe('mapAuthError', () => {
  it('maps a known top-level code to friendly copy and the field', () => {
    const info = mapAuthError(
      {
        status: 422,
        message: 'An account with this email already exists. Please log in or reset your password.',
        code: 'email_taken',
        errors: { email: ['An account with this email already exists. Please log in or reset your password.'] },
        codes: { email: 'email_taken' },
      },
      'Registration failed',
    );
    expect(info.code).toBe('email_taken');
    expect(info.message).toBe(AUTH_ERROR_MESSAGES.email_taken);
    expect(info.fieldErrors.email).toBe(AUTH_ERROR_MESSAGES.email_taken);
    expect(info.fieldCodes.email).toBe('email_taken');
  });

  it('keeps every field error with its own code', () => {
    const info = mapAuthError(
      {
        status: 422,
        code: 'email_taken',
        errors: { email: ['x'], username: ['y'], password: ['z'] },
        codes: { email: 'email_taken', username: 'username_taken', password: 'password_mismatch' },
      },
      'f',
    );
    expect(info.fieldErrors).toEqual({
      email: AUTH_ERROR_MESSAGES.email_taken,
      username: AUTH_ERROR_MESSAGES.username_taken,
      password: AUTH_ERROR_MESSAGES.password_mismatch,
    });
  });

  it('falls back to the backend field text for unknown field codes', () => {
    const info = mapAuthError(
      { status: 422, errors: { email: ['The email field must be a valid email address.'] }, codes: { email: 'email_invalid' } },
      'f',
    );
    expect(info.code).toBeNull();
    expect(info.fieldErrors.email).toBe('The email field must be a valid email address.');
    expect(info.message).toBe('The email field must be a valid email address.');
  });

  it('maps login and 2FA codes', () => {
    expect(getAuthErrorMessage({ status: 422, code: 'invalid_credentials', errors: { email: ['Invalid email or password.'] } }, 'f'))
      .toBe(AUTH_ERROR_MESSAGES.invalid_credentials);
    expect(getAuthErrorMessage({ status: 422, code: 'two_factor_code_invalid', reason: 'wrong_code' }, 'f'))
      .toBe(AUTH_ERROR_MESSAGES.two_factor_code_invalid);
    expect(getAuthErrorMessage({ status: 422, code: 'two_factor_code_expired' }, 'f')).toBe(AUTH_ERROR_MESSAGES.two_factor_code_expired);
    expect(getAuthErrorMessage({ status: 422, code: 'two_factor_locked' }, 'f')).toBe(AUTH_ERROR_MESSAGES.two_factor_locked);
    expect(getAuthErrorMessage({ status: 422, code: 'verification_code_expired' }, 'f')).toBe(AUTH_ERROR_MESSAGES.verification_code_expired);
    expect(getAuthErrorMessage({ status: 422, code: 'verification_locked' }, 'f')).toBe(AUTH_ERROR_MESSAGES.verification_locked);
  });

  it('keeps real 500s generic and never shows unknown codes raw', () => {
    expect(getAuthErrorMessage({ status: 500, message: 'Server Error', code: 'something_new' }, 'f')).toBe(SERVER_ERROR_MESSAGE);
    expect(getAuthErrorMessage({ status: 500, code: 'reset_failed', message: 'We could not reset your password right now. Please try again in a moment.' }, 'f'))
      .toBe(AUTH_ERROR_MESSAGES.reset_failed);
    expect(getAuthErrorMessage(new TypeError('Network request failed'), 'f')).toMatch(/connection/i);
    expect(getAuthErrorMessage({ status: 422, message: 'SQLSTATE[23000] at /app.php:1' }, 'fallback text')).toBe('fallback text');
  });

  it('isKnownAuthCode only accepts documented codes', () => {
    expect(isKnownAuthCode('email_taken')).toBe(true);
    expect(isKnownAuthCode('toString')).toBe(false);
    expect(isKnownAuthCode(undefined)).toBe(false);
  });
});

describe('classifyResetError', () => {
  it('distinguishes expired from invalid links', () => {
    expect(classifyResetError({ status: 422, code: 'reset_token_expired', reason: 'expired' })).toBe('expired');
    expect(classifyResetError({ status: 422, code: 'reset_token_invalid', reason: 'invalid_or_expired' })).toBe('invalid');
    // Older backend shape (no code).
    expect(classifyResetError({ status: 422, message: 'This password reset link is invalid or has expired.', reason: 'invalid_or_expired' })).toBe('invalid');
    expect(classifyResetError({ status: 422, errors: { password: ['too short'] } })).toBeNull();
    expect(classifyResetError({ status: 500, code: 'reset_failed' })).toBeNull();
  });
});

describe('validatePasswordPair', () => {
  it('requires 8+ chars and a matching confirmation', () => {
    expect(validatePasswordPair('', '')).toEqual({ password: 'Password is required' });
    expect(validatePasswordPair('short', 'short')).toEqual({ password: 'Password must be at least 8 characters' });
    expect(validatePasswordPair('longenough', '')).toEqual({ password_confirmation: 'Please confirm your password' });
    expect(validatePasswordPair('longenough', 'different1')).toEqual({ password_confirmation: 'Passwords do not match.' });
    expect(validatePasswordPair('longenough', 'longenough')).toEqual({});
  });
});
