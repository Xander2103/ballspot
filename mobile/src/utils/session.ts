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
