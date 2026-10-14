/**
 * Registration flow decisions, kept free of React Native so they are unit
 * tested (see __tests__/registerFlow.test.ts). The screen only wires state.
 *
 * v1.9.13 anti-bot additions:
 *  - the payload always carries the honeypot field EMPTY (the backend blocks
 *    an IP that fills it; real users never see the field);
 *  - a backend 429 (`registration_rate_limited`) or CAPTCHA code lands as one
 *    friendly form sentence — the form stays editable, nothing is trapped.
 */
import { mapAuthError } from './authErrors';

/** Hidden honeypot field name — must match RegisterRequest::HONEYPOT_FIELD. */
export const REGISTER_HONEYPOT_FIELD = 'website';

export interface RegisterInput {
  name: string;
  username: string;
  email: string;
  password: string;
  confirm: string;
  language: string;
  betaGate: boolean;
  betaCode: string;
  /** Turnstile widget token — only when the backend requires a CAPTCHA (off for launch). */
  captchaToken?: string | null;
}

export interface RegisterPayload {
  name: string;
  username: string;
  email: string;
  password: string;
  password_confirmation: string;
  preferred_language: string;
  terms_accepted: true;
  age_confirmed: true;
  beta_code?: string;
  captcha_token?: string;
  website: '';
}

export function buildRegisterPayload(input: RegisterInput): RegisterPayload {
  const betaCode = input.betaCode.trim();
  const captchaToken = (input.captchaToken ?? '').trim();
  return {
    name: input.name.trim(),
    username: input.username.trim(),
    email: input.email.trim(),
    password: input.password,
    password_confirmation: input.confirm,
    preferred_language: input.language,
    terms_accepted: true,
    age_confirmed: true,
    ...(input.betaGate && betaCode ? { beta_code: betaCode } : {}),
    ...(captchaToken ? { captcha_token: captchaToken } : {}),
    // Honeypot: always present, always empty. Never rendered.
    [REGISTER_HONEYPOT_FIELD]: '',
  };
}

export type RegisterSuccessRoute = 'app' | 'verify_email';

/** Where to go after a 201: straight in when the backend already verified the email. */
export function registerSuccessRoute(res: { email_verified?: boolean }): RegisterSuccessRoute {
  return res.email_verified === true ? 'app' : 'verify_email';
}

export interface RegisterFailureView<F extends string> {
  /** Friendly per-field copy for fields the form renders. */
  fieldErrors: Partial<Record<F, string>>;
  /** One friendly sentence for everything else (rate limit, CAPTCHA, unknown). */
  formError: string;
  /** The server asked for a beta code the form was hiding. */
  revealBetaField: boolean;
  /** Stable backend code, when known. */
  code: string | null;
  /** True for a 429: the form stays as it is; the user just waits a bit. */
  rateLimited: boolean;
}

/**
 * Turn an API failure into what the form shows. Known field codes land on
 * their field; a rate limit or CAPTCHA code becomes one calm sentence; raw
 * backend text never reaches the screen.
 */
export function registerFailureView<F extends string>(e: unknown, fallback: string, knownFields: readonly F[]): RegisterFailureView<F> {
  const info = mapAuthError(e, fallback);
  const status = (e as { status?: number } | null)?.status;
  const fieldErrors: Partial<Record<F, string>> = {};
  const other: string[] = [];

  for (const [field, text] of Object.entries(info.fieldErrors)) {
    // The honeypot and CAPTCHA fields are not visible form fields: their
    // message goes to the form-level sentence, never to a missing input.
    if (knownFields.includes(field as F) && field !== REGISTER_HONEYPOT_FIELD && field !== 'captcha_token') {
      fieldErrors[field as F] = text;
    } else if (text) {
      other.push(text);
    }
  }

  const rateLimited = status === 429 || info.code === 'registration_rate_limited' || info.code === 'rate_limited';
  const hasFieldErrors = Object.keys(fieldErrors).length > 0;
  const formError = rateLimited || !hasFieldErrors ? info.message : (other[0] ?? '');

  return {
    fieldErrors: rateLimited ? {} : fieldErrors,
    formError,
    revealBetaField: 'beta_code' in fieldErrors,
    code: info.code,
    rateLimited,
  };
}
