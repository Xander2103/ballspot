/**
 * Decision logic for the "Create account → verify email" flow, kept free of
 * React Native so it is unit-tested (see __tests__/verificationFlow.test.ts).
 *
 * Why this exists: on TestFlight a freshly registered account's code was
 * reported as "invalid". Every input that can make that happen is handled
 * here explicitly — a stale token from a previous account on the same
 * device, a screen showing an email the token does not belong to, codes typed
 * with spaces, and error bodies that need a specific friendly message.
 */

import { getApiErrorMessage } from './apiError';
import { translate } from '../i18n/core';

// --- Auth state handover ---------------------------------------------------

export interface TokenStore {
  get(): Promise<string | null>;
  save(token: string): Promise<void>;
  remove(): Promise<void>;
}

/**
 * Before registering a NEW account, drop whatever credential the previous
 * account left on the device, so the register request never carries a stale
 * Authorization header and a failed registration cannot leave the old session
 * half-alive. `clearLocalState` is the app's full local sign-out (reminders,
 * theme, flags); it runs first so a throw there can never keep the token.
 */
export async function prepareForNewAccount(store: TokenStore, clearLocalState?: () => Promise<void>): Promise<void> {
  try { await clearLocalState?.(); } catch { /* best effort */ }
  try { await store.remove(); } catch { /* best effort */ }
}

/** Persist the freshly issued token, replacing any old one, and prove it stuck. */
export async function adoptToken(store: TokenStore, token: string): Promise<void> {
  if (!token || typeof token !== 'string') {
    throw new Error(translate('errors.verification.noToken'));
  }
  await store.save(token);
  const stored = await store.get();
  if (stored !== token) {
    throw new Error(translate('errors.verification.storeFailed'));
  }
}

// --- Which account is this screen verifying? -------------------------------

export interface VerificationTarget {
  /** The email to display — always the token's account when known. */
  email: string | null;
  /** True when the screen was opened for one account but the token belongs to another. */
  mismatch: boolean;
}

export function resolveVerificationTarget(routeEmail: string | null | undefined, tokenEmail: string | null | undefined): VerificationTarget {
  const route = (routeEmail ?? '').trim();
  const token = (tokenEmail ?? '').trim();
  if (!token) return { email: route || null, mismatch: false };
  if (!route) return { email: token, mismatch: false };
  return { email: token, mismatch: route.toLowerCase() !== token.toLowerCase() };
}

// --- Request payload -------------------------------------------------------

/** Digits only, exactly six, or null. */
export function normalizeCode(raw: string): string | null {
  const digits = (raw ?? '').replace(/\D+/g, '');
  return digits.length === 6 ? digits : null;
}

export interface VerifyPayload {
  code: string;
  email?: string;
}

/**
 * The verify request always sends the code for the TOKEN's account; the email
 * is included as a hint so the server can flag a session mismatch instead of
 * answering "invalid code".
 */
export function buildVerifyPayload(rawCode: string, targetEmail: string | null): VerifyPayload | null {
  const code = normalizeCode(rawCode);
  if (!code) return null;
  const email = (targetEmail ?? '').trim();
  return email ? { code, email } : { code };
}

// --- Outcomes --------------------------------------------------------------

export type VerificationFailure =
  | { kind: 'wrong_code'; message: string }
  | { kind: 'expired'; message: string }
  | { kind: 'locked'; message: string }
  | { kind: 'no_code'; message: string }
  | { kind: 'session_mismatch'; message: string }
  | { kind: 'unauthorized'; message: string }
  | { kind: 'other'; message: string };

/** Backend `reason` → translation key (copy lives in i18n, per language). */
const REASON_KEYS: Record<string, string> = {
  wrong_code: 'errors.auth.verification_code_invalid',
  expired: 'errors.auth.verification_code_expired',
  locked: 'errors.auth.verification_locked',
  no_code: 'errors.auth.verification_no_code',
  session_mismatch: 'errors.verification.sessionMismatch',
};

/** Map an API error to a specific, friendly failure. Never surfaces raw server text. */
export function classifyVerificationError(e: unknown): VerificationFailure {
  const err = (e && typeof e === 'object' ? e : {}) as { status?: number; reason?: unknown; message?: unknown };
  const reason = typeof err.reason === 'string' ? err.reason : null;

  if (err.status === 401) {
    return { kind: 'unauthorized', message: translate('errors.verification.sessionExpired') };
  }
  if (err.status === 409 || reason === 'session_mismatch') {
    return { kind: 'session_mismatch', message: translate(REASON_KEYS.session_mismatch) };
  }
  if (reason && reason in REASON_KEYS) {
    return { kind: reason as VerificationFailure['kind'], message: translate(REASON_KEYS[reason]) };
  }
  return { kind: 'other', message: getApiErrorMessage(e, translate('errors.verification.invalidOrExpired')) };
}

/** After a successful verification: onboarding if no sport chosen yet, else Home. */
export function routeAfterVerification(me: { preferred_sport?: unknown } | null | undefined): 'Home' | 'SportSelection' {
  return me && me.preferred_sport ? 'Home' : 'SportSelection';
}

/** The notice shown after a resend. Always names the account the code went to. */
export function resendNotice(targetEmail: string | null): string {
  const to = (targetEmail ?? '').trim();
  return to ? translate('errors.verification.resentTo', { email: to }) : translate('errors.verification.resent');
}
