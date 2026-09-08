/**
 * Friendly copy for the account/auth error CODES the backend returns
 * (backend/app/Support/AuthError.php). Add a code there, add it here.
 *
 * The backend answers known failures as
 *   { message, code, errors?: { field: [msg] }, codes?: { field: code }, reason? }
 * Real 500s stay generic (getApiErrorMessage → SERVER_ERROR_MESSAGE). Nothing
 * here ever surfaces raw server text for a known code — the copy below wins.
 */

import { getApiErrorMessage } from './apiError';

export const AUTH_ERROR_MESSAGES: Record<string, string> = {
  // Registration
  email_taken: 'An account with this email already exists. Please log in or reset your password.',
  username_taken: 'This username is already taken.',
  password_mismatch: 'Passwords do not match.',
  // Login
  invalid_credentials: 'Invalid email or password.',
  account_deleted: 'This account has been deleted. You can create a new account with the same email.',
  // Login 2FA
  two_factor_required: 'We sent a verification code to your email.',
  two_factor_code_invalid: 'That code is not correct. Check the newest email and try again.',
  two_factor_code_expired: 'This code has expired. Please log in again to get a new one.',
  two_factor_locked: 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
  two_factor_session_invalid: 'This login session has expired. Please log in again.',
  // Email verification (registration)
  verification_code_invalid: 'That code is not correct. Check the newest email and try again.',
  verification_code_expired: 'This code has expired. Tap "Resend code" to get a new one.',
  verification_locked: 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
  verification_no_code: 'No code is active for this account. Tap "Resend code" to get a new one.',
  // Password reset
  reset_token_invalid: 'This reset link is invalid. Request a new one and use the newest email.',
  reset_token_expired: 'This reset link has expired. Request a new one and use the newest email.',
  reset_failed: 'We could not reset your password right now. Please try again in a moment.',
};

export interface AuthErrorInfo {
  /** Stable code, when the backend sent one we know. */
  code: string | null;
  /** Friendly sentence — always safe to show. */
  message: string;
  /** Per-field friendly messages (register / reset forms). */
  fieldErrors: Record<string, string>;
  /** Per-field codes when the backend sent them. */
  fieldCodes: Record<string, string>;
}

type ErrorBody = {
  status?: number;
  code?: unknown;
  codes?: unknown;
  errors?: unknown;
  message?: unknown;
  reason?: unknown;
};

function asRecord(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {};
}

function firstString(v: unknown): string {
  if (Array.isArray(v)) {
    const s = v.find((x) => typeof x === 'string' && x.trim());
    return typeof s === 'string' ? s.trim() : '';
  }
  return typeof v === 'string' ? v.trim() : '';
}

/** True when `code` is one we have copy for. */
export function isKnownAuthCode(code: unknown): code is string {
  return typeof code === 'string' && Object.prototype.hasOwnProperty.call(AUTH_ERROR_MESSAGES, code);
}

/**
 * Map whatever the API client threw into a friendly, code-aware result.
 * Priority: known top-level code → known field codes → backend field messages
 * → getApiErrorMessage (which hides 5xx/technical text) → fallback.
 */
export function mapAuthError(e: unknown, fallback: string): AuthErrorInfo {
  const err = asRecord(e) as ErrorBody;
  const fieldCodes: Record<string, string> = {};
  const fieldErrors: Record<string, string> = {};

  for (const [field, code] of Object.entries(asRecord(err.codes))) {
    if (typeof code === 'string') fieldCodes[field] = code;
  }
  for (const [field, messages] of Object.entries(asRecord(err.errors))) {
    const code = fieldCodes[field];
    const friendly = isKnownAuthCode(code) ? AUTH_ERROR_MESSAGES[code] : '';
    const text = friendly || firstString(messages);
    if (text) fieldErrors[field] = text;
  }

  const code = isKnownAuthCode(err.code) ? err.code : null;
  let message: string;
  if (code) {
    message = AUTH_ERROR_MESSAGES[code];
  } else {
    const firstField = Object.values(fieldErrors)[0];
    message = firstField || getApiErrorMessage(e, fallback);
  }

  return { code, message, fieldErrors, fieldCodes };
}

/** Convenience: just the sentence. */
export function getAuthErrorMessage(e: unknown, fallback: string): string {
  return mapAuthError(e, fallback).message;
}

/** Password-reset outcomes the reset screen switches its UI on. */
export type ResetLinkProblem = 'expired' | 'invalid' | null;

export function classifyResetError(e: unknown): ResetLinkProblem {
  const err = asRecord(e) as ErrorBody;
  if (err.code === 'reset_token_expired' || err.reason === 'expired') return 'expired';
  if (err.code === 'reset_token_invalid' || err.reason === 'invalid_or_expired') return 'invalid';
  if (err.status === 422 && !err.errors) return 'invalid';
  return null;
}

/** Validate the two password fields the way the backend does. */
export function validatePasswordPair(password: string, confirmation: string): { password?: string; password_confirmation?: string } {
  const out: { password?: string; password_confirmation?: string } = {};
  if (!password) out.password = 'Password is required';
  else if (password.length < 8) out.password = 'Password must be at least 8 characters';
  if (!out.password) {
    if (!confirmation) out.password_confirmation = 'Please confirm your password';
    else if (confirmation !== password) out.password_confirmation = AUTH_ERROR_MESSAGES.password_mismatch;
  }
  return out;
}
