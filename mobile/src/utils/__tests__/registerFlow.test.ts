import { buildRegisterPayload, registerFailureView, registerSuccessRoute, REGISTER_HONEYPOT_FIELD } from '../registerFlow';
import { authErrorMessage } from '../authErrors';

const KNOWN = ['name', 'username', 'email', 'password', 'password_confirmation', 'preferred_language', 'beta_code'] as const;

const input = {
  name: ' Ada ', username: ' ada ', email: ' Ada@Example.com ', password: 'password123', confirm: 'password123',
  language: 'nl', betaGate: false, betaCode: '',
};

describe('register payload', () => {
  it('always carries the honeypot field empty and never a captcha token unless given', () => {
    const payload = buildRegisterPayload(input);
    expect(REGISTER_HONEYPOT_FIELD).toBe('website');
    expect(payload.website).toBe('');
    expect(payload).not.toHaveProperty('captcha_token');
    expect(payload).not.toHaveProperty('beta_code');
    expect(payload).toMatchObject({ name: 'Ada', username: 'ada', email: 'Ada@Example.com', terms_accepted: true, age_confirmed: true, preferred_language: 'nl' });
  });

  it('adds the beta code only when the gate is on, and the captcha token only when present', () => {
    expect(buildRegisterPayload({ ...input, betaGate: true, betaCode: ' BETA1 ' }).beta_code).toBe('BETA1');
    expect(buildRegisterPayload({ ...input, betaGate: false, betaCode: 'BETA1' })).not.toHaveProperty('beta_code');
    expect(buildRegisterPayload({ ...input, captchaToken: ' tok ' }).captcha_token).toBe('tok');
    expect(buildRegisterPayload({ ...input, captchaToken: '' })).not.toHaveProperty('captcha_token');
  });
});

describe('register success routing', () => {
  it('goes to email verification on a normal success and straight in when already verified', () => {
    expect(registerSuccessRoute({ email_verified: false })).toBe('verify_email');
    expect(registerSuccessRoute({})).toBe('verify_email');
    expect(registerSuccessRoute({ email_verified: true })).toBe('app');
  });
});

describe('register failure view', () => {
  it('shows the friendly rate-limit sentence and keeps the form intact (no trap)', () => {
    const view = registerFailureView(
      { status: 429, code: 'registration_rate_limited', retry_after: 42, message: 'Too many attempts. Try again in 42 seconds.' },
      'Registration failed', KNOWN,
    );
    expect(view.rateLimited).toBe(true);
    expect(view.formError).toBe('Too many attempts. Please wait a bit and try again.');
    expect(view.fieldErrors).toEqual({});
    expect(view.revealBetaField).toBe(false);
    expect(view.code).toBe('registration_rate_limited');
  });

  it('a plain 429 without a code still reads as a calm rate-limit message', () => {
    const view = registerFailureView({ status: 429, retry_after: 30, message: 'Too many attempts. Try again in 30 seconds.' }, 'f', KNOWN);
    expect(view.rateLimited).toBe(true);
    expect(view.formError).toBe('Too many attempts. Try again in 30 seconds.');
    expect(view.fieldErrors).toEqual({});
  });

  it('maps CAPTCHA codes to friendly copy at form level, never to a missing field', () => {
    const required = registerFailureView(
      { status: 422, code: 'registration_captcha_required', errors: { captcha_token: ['x'] }, codes: { captcha_token: 'registration_captcha_required' } },
      'f', KNOWN,
    );
    expect(required.formError).toBe(authErrorMessage('registration_captcha_required'));
    expect(required.fieldErrors).toEqual({});

    const failed = registerFailureView(
      { status: 422, code: 'registration_captcha_failed', errors: { captcha_token: ['x'] }, codes: { captcha_token: 'registration_captcha_failed' } },
      'f', KNOWN,
    );
    expect(failed.formError).toBe(authErrorMessage('registration_captcha_failed'));
    expect(failed.rateLimited).toBe(false);
  });

  it('keeps known field errors on their fields and reveals the beta field when asked', () => {
    const view = registerFailureView(
      { status: 422, code: 'email_taken', errors: { email: ['taken'], beta_code: ['A beta code is required.'] }, codes: { email: 'email_taken', beta_code: 'beta_code_invalid' } },
      'f', KNOWN,
    );
    expect(view.fieldErrors.email).toBe(authErrorMessage('email_taken'));
    expect(view.fieldErrors.beta_code).toBe('A beta code is required.');
    expect(view.revealBetaField).toBe(true);
    expect(view.formError).toBe('');
    expect(view.rateLimited).toBe(false);
  });

  it('never shows the honeypot rejection as a field and never raw server text', () => {
    const view = registerFailureView(
      { status: 422, code: 'validation_failed', errors: { website: ['Please check the highlighted fields.'] }, codes: { website: 'validation_failed' } },
      'Registration failed', KNOWN,
    );
    expect(view.fieldErrors).toEqual({});
    expect(view.formError).toBe(authErrorMessage('validation_failed'));

    const raw = registerFailureView({ status: 500, message: 'SQLSTATE[23000] at /app.php:1' }, 'Registration failed', KNOWN);
    expect(raw.formError).not.toMatch(/SQLSTATE/);
  });
});
