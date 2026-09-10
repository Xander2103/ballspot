/**
 * Friendly copy for the account/auth error CODES the backend returns
 * (backend/app/Support/AuthError.php). Add a code there, add it to
 * src/i18n/locales/en/errors.ts (auth.*) and every other language.
 *
 * The backend answers known failures as
 *   { message, code, errors?: { field: [msg] }, codes?: { field: code }, reason? }
 * Real 500s stay generic (getApiErrorMessage → server error copy). Nothing
 * here ever surfaces raw server text for a known code — the translated copy
 * for the CODE wins, in the app's active language.
 */

import { getApiErrorMessage } from './apiError';
import { translate } from '../i18n/core';
import { en } from '../i18n/locales/en';

/** The codes we have copy for (English is the source of truth). */
export const AUTH_ERROR_CODES: readonly string[] = Object.keys(en.errors.auth);

/** Translated sentence for a known code, in the active language. */
export function authErrorMessage(code: string): string {
  return translate(`errors.auth.${code}`);
}

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
  return typeof code === 'string' && AUTH_ERROR_CODES.includes(code);
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
    const friendly = isKnownAuthCode(code) ? authErrorMessage(code) : '';
    const text = friendly || firstString(messages);
    if (text) fieldErrors[field] = text;
  }

  const code = isKnownAuthCode(err.code) ? err.code : null;
  let message: string;
  if (code) {
    message = authErrorMessage(code);
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

/** Validate the two password fields the way the backend does (translated). */
export function validatePasswordPair(password: string, confirmation: string): { password?: string; password_confirmation?: string } {
  const out: { password?: string; password_confirmation?: string } = {};
  if (!password) out.password = translate('errors.validation.passwordRequired');
  else if (password.length < 8) out.password = translate('errors.validation.passwordMin');
  if (!out.password) {
    if (!confirmation) out.password_confirmation = translate('errors.validation.passwordConfirmRequired');
    else if (confirmation !== password) out.password_confirmation = authErrorMessage('password_mismatch');
  }
  return out;
}
