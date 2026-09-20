/**
 * Session decision logic — pure TypeScript (no React Native) so it runs under
 * the plain ts-jest runner (see __tests__/session.test.ts).
 *
 * Why this exists: on TestFlight a user could log in, land on Home with
 * "Hey, …" / "@…" and find a Profile tab that said "Couldn't load your
 * profile" with only a Retry button — no way out. Three code paths let a
 * token that does NOT resolve to a usable account into the tab app, and no
 * screen inside offered a logout. Everything that decides "is this session
 * usable, and what do we do when it is not" now lives here:
 *
 *  - classifySessionError: invalid (drop the token, go to Login) vs
 *    recoverable (offline / 5xx: stay, offer Retry + Logout)
 *  - bootstrapSession: the app-start token check
 *  - logoutLocally: local sign-out that always succeeds
 *  - a tiny session-invalid event bus the API client raises into and the
 *    navigator listens on, so a dead session anywhere in the app ends on the
 *    Login screen with a clear message
 *  - the view models for Home's identity header and Profile's error state
 */

import { isNetworkError } from './apiError';
import { translate } from '../i18n/core';
import type { User } from '../types/auth';

// --- Response bodies -------------------------------------------------------

/**
 * Parse an HTTP body as JSON the way the app needs it, not the way
 * JSON.parse insists on it:
 *  - a leading UTF-8 byte-order mark is dropped. A PHP source file saved
 *    with a BOM makes an un-cached backend echo "﻿" ahead of EVERY
 *    JSON body; the HTTP status is a healthy 200 and JSON.parse still throws,
 *    which made every screen report a "network" failure (Sep 2026).
 *  - an empty body is `null` (204 / empty 200), never an exception.
 * Anything else that is not JSON throws a plain Error the client turns into
 * a `malformed_response` rejection.
 */
export function parseJsonBody(text: string): unknown {
  const trimmed = text.replace(/^﻿/, '').trim();
  if (trimmed === '') return null;
  return JSON.parse(trimmed);
}

/** Stable client-side code for a body the app could not read. */
export const MALFORMED_RESPONSE = 'malformed_response';
/** Stable client-side code for a /me body missing the fields the app needs. */
export const MALFORMED_PROFILE = 'malformed_profile';

/**
 * Turn a /me body into the User the app renders, tolerating what a backend
 * of any recent version may send:
 *  - wrapped (`{ data: {...} }`, JsonResource) or bare
 *  - missing optional fields (older backend, NULL columns) → safe defaults
 *  - only `id` + `username` are required; `name` falls back to the username
 * A body without those throws a `malformed_profile` rejection (never a crash
 * deeper in a screen), which the caller treats as recoverable.
 */
export function parseProfile(raw: unknown): User {
  const outer = raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : null;
  const inner = outer && outer.data && typeof outer.data === 'object' ? (outer.data as Record<string, unknown>) : outer;

  const id = inner ? Number(inner.id) : NaN;
  const username = inner && typeof inner.username === 'string' ? inner.username.trim() : '';
  if (!inner || !Number.isFinite(id) || id <= 0 || !username) {
    throw { status: 200, code: MALFORMED_PROFILE, message: translate('errors.server'), reason: describeShape(inner) };
  }

  const str = (v: unknown, fallback: string) => (typeof v === 'string' && v.trim() ? v : fallback);
  const sport = inner.preferred_sport;

  return {
    id,
    name: str(inner.name, username),
    username,
    email: typeof inner.email === 'string' ? inner.email : undefined,
    // Absent on older backends: treat as verified rather than bouncing a
    // working account to the verification screen.
    email_verified: typeof inner.email_verified === 'boolean' ? inner.email_verified : true,
    selected_theme: str(inner.selected_theme, 'pitch_green'),
    preferred_language: str(inner.preferred_language, 'en'),
    two_factor_enabled: inner.two_factor_enabled === true,
    avatar_url: typeof inner.avatar_url === 'string' && inner.avatar_url ? inner.avatar_url : null,
    preferred_sport: sport && typeof sport === 'object' && Number.isFinite(Number((sport as { id?: unknown }).id))
      ? (sport as User['preferred_sport'])
      : null,
  };
}

/** Safe one-word description of a bad body for logs (never its content). */
function describeShape(inner: Record<string, unknown> | null): string {
  if (!inner) return 'not_an_object';
  const missing = ['id', 'username'].filter((k) => inner[k] === undefined || inner[k] === null || inner[k] === '');
  return missing.length ? `missing_${missing.join('_')}` : 'invalid_id';
}

// --- Failure summaries (safe to log) ---------------------------------------

export interface ApiFailureSummary {
  endpoint: string;
  /** HTTP status, or null for a network failure / parse failure without one. */
  status: number | null;
  /** Stable backend or client code when there is one. */
  code: string | null;
  kind: 'network' | 'invalid' | 'recoverable';
}

/**
 * What a developer (Metro) or a bug report needs about a failed request:
 * endpoint, status and stable code. Deliberately NOT the message — backend
 * messages can contain user text, and a raw 500 body is noise.
 */
export function describeApiFailure(e: unknown, endpoint: string): ApiFailureSummary {
  const err = asError(e);
  const status = typeof err.status === 'number' ? err.status : null;
  const code = typeof err.code === 'string' ? err.code : null;
  const kind: ApiFailureSummary['kind'] = isNetworkError(e) ? 'network' : classifySessionError(e, endpoint.replace(/^[A-Z]+\s+/, ''));
  return { endpoint, status, code, kind };
}

export function formatApiFailure(s: ApiFailureSummary): string {
  return `[api] ${s.endpoint} -> ${s.status ?? 'no-response'}${s.code ? ` ${s.code}` : ''} (${s.kind})`;
}

// --- Profile load outcome ----------------------------------------------------

export type ProfileLoadInput =
  | { ok: true; raw: unknown }
  | { ok: false; error: unknown };

export type ProfileLoadOutcome =
  /** Render the profile. */
  | { kind: 'ready'; user: User }
  /** Token is dead: the navigator resets to Login (the client already raised it). */
  | { kind: 'session_invalid'; failure: ApiFailureSummary }
  /** Stay on the screen with Retry + Logout. */
  | { kind: 'failed'; view: ProfileFailureView; failure: ApiFailureSummary };

/**
 * One decision for the Profile screen from whatever /me produced. A body
 * that cannot be parsed is a failure like any other (Retry + Logout), never
 * an exception inside render.
 */
export function resolveProfileLoad(input: ProfileLoadInput, endpoint = 'GET /me'): ProfileLoadOutcome {
  let error: unknown;
  if (input.ok) {
    try {
      return { kind: 'ready', user: parseProfile(input.raw) };
    } catch (e: unknown) {
      error = e;
    }
  } else {
    error = input.error;
  }

  const failure = describeApiFailure(error, endpoint);
  if (failure.kind === 'invalid') {
    return { kind: 'session_invalid', failure };
  }
  return { kind: 'failed', view: profileLoadFailureView('recoverable'), failure };
}

// --- Classification --------------------------------------------------------

export type SessionFailureKind = 'invalid' | 'recoverable';

/** Backend AuthError codes that mean "this token is dead, forget it". */
export const SESSION_INVALID_CODES: readonly string[] = ['account_deleted', 'session_invalid'];

/** Public endpoints where a 401/403 is about the request, never the stored session. */
const PUBLIC_PATHS = ['/login', '/login/verify', '/login/resend-code', '/register', '/forgot-password', '/reset-password', '/config', '/health'];

type ErrorLike = { status?: unknown; code?: unknown; message?: unknown };

function asError(e: unknown): ErrorLike {
  return e && typeof e === 'object' ? (e as ErrorLike) : {};
}

export function hasSessionInvalidCode(body: unknown): boolean {
  const code = asError(body).code;
  return typeof code === 'string' && SESSION_INVALID_CODES.includes(code);
}

/**
 * Decide what a failed request means for the stored session.
 *
 *  - `account_deleted` / `session_invalid` in the body → invalid, whatever the status
 *  - 401 on any authenticated path → invalid
 *  - 403 / 404 → invalid only for the profile check itself (`/me`): elsewhere a
 *    403 is "not verified" / "not a member" and a 404 is a missing resource
 *  - everything else (offline, 5xx, 429, unknown) → recoverable
 */
export function classifySessionError(e: unknown, path = '/me'): SessionFailureKind {
  if (isNetworkError(e)) return 'recoverable';
  const err = asError(e);
  if (hasSessionInvalidCode(err)) return 'invalid';
  const status = typeof err.status === 'number' ? err.status : undefined;
  if (status === 401) return 'invalid';
  if (isMePath(path) && (status === 403 || status === 404)) return 'invalid';
  return 'recoverable';
}

function isMePath(path: string): boolean {
  return path === '/me' || path.startsWith('/me?');
}

/**
 * Used by the API client on every non-OK response: should this failure end
 * the session app-wide? Public auth endpoints never do (a 401 there is about
 * the code the user typed), and `/logout` never does (the caller is already
 * signing out; a dead token is the expected outcome).
 */
export function isSessionInvalidResponse(status: number, body: unknown, path: string, hadToken: boolean): boolean {
  if (!hadToken) return false;
  const bare = path.split('?')[0];
  if (PUBLIC_PATHS.includes(bare) || bare === '/logout') return false;
  return classifySessionError({ status, ...asError(body) }, bare) === 'invalid';
}

/** The sentence shown on Login after a session ends: "Your session has expired…". */
export function sessionExpiredMessage(): string {
  return translate('errors.unauthorized');
}

// --- Session-invalid event bus ---------------------------------------------

export type SessionInvalidReason = 'unauthorized' | 'account_deleted';

type SessionListener = (reason: SessionInvalidReason) => void;
const sessionListeners = new Set<SessionListener>();

export function onSessionInvalid(listener: SessionListener): () => void {
  sessionListeners.add(listener);
  return () => { sessionListeners.delete(listener); };
}

export function emitSessionInvalid(reason: SessionInvalidReason): void {
  sessionListeners.forEach((l) => { try { l(reason); } catch { /* one bad listener must not break the rest */ } });
}

export function sessionInvalidReasonFor(body: unknown): SessionInvalidReason {
  return asError(body).code === 'account_deleted' ? 'account_deleted' : 'unauthorized';
}

// --- App start -------------------------------------------------------------

export interface SessionUser {
  name?: string;
  username?: string;
  email_verified?: boolean;
  preferred_sport?: unknown;
  preferred_language?: string | null;
  selected_theme?: string | null;
}

export interface BootstrapDeps<U extends SessionUser> {
  getToken: () => Promise<string | null>;
  fetchMe: () => Promise<U>;
  /** Full local sign-out (token, reminders, per-account flags). Must not throw. */
  clearLocal: () => Promise<void>;
}

export type BootstrapResult<U extends SessionUser> =
  | { kind: 'signed_out'; reason: 'no_token' | 'session_invalid' }
  | { kind: 'ready'; user: U }
  | { kind: 'recoverable'; error: unknown };

/**
 * Validate the stored token against /me on app start.
 *   no token            → signed_out (Login)
 *   /me ok              → ready (route via routeForUser)
 *   401/403/404/deleted → token cleared, signed_out with a session_invalid reason
 *   offline / 5xx       → recoverable: the caller shows Retry + Logout, never
 *                         a tab app with placeholders and no way out
 */
export async function bootstrapSession<U extends SessionUser>(deps: BootstrapDeps<U>): Promise<BootstrapResult<U>> {
  const token = await deps.getToken().catch(() => null);
  if (!token) return { kind: 'signed_out', reason: 'no_token' };

  try {
    const user = await deps.fetchMe();
    return { kind: 'ready', user };
  } catch (e: unknown) {
    if (classifySessionError(e, '/me') === 'invalid') {
      try { await deps.clearLocal(); } catch { /* best effort */ }
      return { kind: 'signed_out', reason: 'session_invalid' };
    }
    return { kind: 'recoverable', error: e };
  }
}

export type LandingRoute = 'Home' | 'SportSelection' | 'EmailVerification';

/** Unverified email → verify first; no sport yet → onboarding; else Home. */
export function routeForUser(user: SessionUser): LandingRoute {
  if (user.email_verified === false) return 'EmailVerification';
  return user.preferred_sport ? 'Home' : 'SportSelection';
}

// --- Logout / delete -------------------------------------------------------

export interface LogoutDeps {
  /** POST /logout — best effort; a dead network or dead token must not block. */
  apiLogout?: () => Promise<unknown>;
  /** Push de-registration — best effort, runs while the token still works. */
  unregisterPush?: () => Promise<unknown>;
  /** Full local sign-out. Runs last and ALWAYS runs. */
  clearLocal: () => Promise<void>;
}

/**
 * Log out. Server-side calls first (while the token is still valid), then the
 * local wipe. Never throws: whatever the API does, the device ends signed out.
 */
export async function logoutLocally(deps: LogoutDeps): Promise<void> {
  try { await deps.unregisterPush?.(); } catch { /* best effort */ }
  try { await deps.apiLogout?.(); } catch { /* best effort */ }
  try { await deps.clearLocal(); } catch { /* best effort — the caller still leaves for Login */ }
}

export interface DeleteAccountDeps {
  deleteAccount: () => Promise<unknown>;
  clearLocal: () => Promise<void>;
}

export type DeleteAccountOutcome =
  /** Deleted server-side; local state cleared. Go to Login. */
  | { outcome: 'deleted' }
  /** The session was already dead — nothing to delete with. Local state cleared. Go to Login. */
  | { outcome: 'session_gone' }
  /** Server refused (admin account, 5xx, offline). Nothing cleared; show the error. */
  | { outcome: 'failed'; error: unknown };

export async function deleteAccountAndSignOut(deps: DeleteAccountDeps): Promise<DeleteAccountOutcome> {
  try {
    await deps.deleteAccount();
  } catch (e: unknown) {
    if (classifySessionError(e, '/account') === 'invalid') {
      try { await deps.clearLocal(); } catch { /* best effort */ }
      return { outcome: 'session_gone' };
    }
    return { outcome: 'failed', error: e };
  }
  try { await deps.clearLocal(); } catch { /* best effort */ }
  return { outcome: 'deleted' };
}

// --- View models -----------------------------------------------------------

export type ProfileLoadState = 'loading' | 'ready' | 'failed';

export type HomeIdentity =
  | { kind: 'loading'; label: string }
  | { kind: 'ready'; greeting: string; handle: string }
  | { kind: 'failed'; message: string; retryLabel: string; logoutLabel: string };

/**
 * What Home's header shows. "Hey, …" is never a final state: while loading
 * the header says so, when the profile is there it shows the real name, and
 * when it could not be loaded it says that and offers Retry + Logout.
 */
export function homeIdentity(state: ProfileLoadState, user: { name?: string; username?: string } | null): HomeIdentity {
  if (state === 'ready' && user && user.name) {
    return { kind: 'ready', greeting: translate('home.greeting', { name: user.name }), handle: `@${user.username ?? ''}` };
  }
  if (state === 'loading') {
    return { kind: 'loading', label: translate('common.states.loading') };
  }
  return {
    kind: 'failed',
    message: translate('home.profileUnavailable'),
    retryLabel: translate('common.buttons.retry'),
    logoutLabel: translate('common.buttons.logout'),
  };
}

export interface ProfileFailureAction {
  id: 'retry' | 'logout';
  label: string;
}

export interface ProfileFailureView {
  title: string;
  message: string;
  actions: ProfileFailureAction[];
}

/**
 * Profile's full-screen error state. Logout is ALWAYS offered; Retry only
 * when retrying can help (the session is still valid).
 */
export function profileLoadFailureView(kind: SessionFailureKind): ProfileFailureView {
  const logout: ProfileFailureAction = { id: 'logout', label: translate('common.buttons.logout') };
  if (kind === 'invalid') {
    return {
      title: translate('profile.screen.loadErrorTitle'),
      message: sessionExpiredMessage(),
      actions: [logout],
    };
  }
  return {
    title: translate('profile.screen.loadErrorTitle'),
    message: translate('common.states.checkConnection'),
    actions: [{ id: 'retry', label: translate('common.buttons.retry') }, logout],
  };
}
