import * as fs from 'fs';
import * as path from 'path';
import {
  classifySessionError,
  isSessionInvalidResponse,
  bootstrapSession,
  routeForUser,
  logoutLocally,
  deleteAccountAndSignOut,
  homeIdentity,
  profileLoadFailureView,
  onSessionInvalid,
  emitSessionInvalid,
  sessionInvalidReasonFor,
  sessionExpiredMessage,
  parseJsonBody,
  parseProfile,
  describeApiFailure,
  formatApiFailure,
  resolveProfileLoad,
  MALFORMED_PROFILE,
} from '../session';

const SRC = path.resolve(__dirname, '..', '..');

function fakeLocal() {
  const state = { token: 'tok' as string | null, cleared: 0 };
  return {
    state,
    getToken: async () => state.token,
    clearLocal: async () => { state.cleared += 1; state.token = null; },
  };
}

const activeUser = { name: 'Alice', username: 'alice', email_verified: true, preferred_sport: { id: 1 } };

describe('classifySessionError', () => {
  it('treats 401 / 403 / 404 from /me as an invalid session', () => {
    expect(classifySessionError({ status: 401, message: 'Unauthenticated.' })).toBe('invalid');
    expect(classifySessionError({ status: 403, message: 'Forbidden' })).toBe('invalid');
    expect(classifySessionError({ status: 404, message: 'Not found' })).toBe('invalid');
  });

  it('treats the stable backend codes as invalid whatever the status', () => {
    expect(classifySessionError({ status: 401, code: 'account_deleted' })).toBe('invalid');
    expect(classifySessionError({ status: 403, code: 'account_deleted' }, '/profile/stats')).toBe('invalid');
    expect(classifySessionError({ status: 401, code: 'session_invalid' }, '/leagues')).toBe('invalid');
  });

  it('keeps network and server failures recoverable', () => {
    expect(classifySessionError(new TypeError('Network request failed'))).toBe('recoverable');
    expect(classifySessionError({ status: 500, message: 'Server Error' })).toBe('recoverable');
    expect(classifySessionError({ status: 503 })).toBe('recoverable');
    expect(classifySessionError({ status: 429, retry_after: 30 })).toBe('recoverable');
    expect(classifySessionError(undefined)).toBe('recoverable');
  });

  it('only escalates 403/404 for the profile check itself', () => {
    expect(classifySessionError({ status: 403 }, '/leagues/4/hide')).toBe('recoverable');
    expect(classifySessionError({ status: 404 }, '/packs/gone')).toBe('recoverable');
    expect(classifySessionError({ status: 401 }, '/leagues')).toBe('invalid');
  });
});

describe('isSessionInvalidResponse (API client hook)', () => {
  it('fires for an authenticated 401 anywhere in the app', () => {
    expect(isSessionInvalidResponse(401, { message: 'Unauthenticated.' }, '/leagues', true)).toBe(true);
    expect(isSessionInvalidResponse(401, { code: 'account_deleted' }, '/me', true)).toBe(true);
  });

  it('never fires for public auth endpoints, for /logout, or without a stored token', () => {
    expect(isSessionInvalidResponse(401, {}, '/login/verify', false)).toBe(false);
    expect(isSessionInvalidResponse(401, {}, '/login/verify', true)).toBe(false);
    expect(isSessionInvalidResponse(422, { code: 'invalid_credentials' }, '/login', false)).toBe(false);
    expect(isSessionInvalidResponse(401, {}, '/logout', true)).toBe(false);
    expect(isSessionInvalidResponse(401, {}, '/me', false)).toBe(false);
  });

  it('never fires for ordinary 403/404/5xx on other endpoints', () => {
    expect(isSessionInvalidResponse(403, { message: 'not a member' }, '/leagues/1', true)).toBe(false);
    expect(isSessionInvalidResponse(404, {}, '/packs/x', true)).toBe(false);
    expect(isSessionInvalidResponse(500, {}, '/me', true)).toBe(false);
  });

  it('names the reason from the backend code', () => {
    expect(sessionInvalidReasonFor({ code: 'account_deleted' })).toBe('account_deleted');
    expect(sessionInvalidReasonFor({ message: 'Unauthenticated.' })).toBe('unauthorized');
  });
});

describe('session-invalid event bus', () => {
  it('delivers to listeners and unsubscribes cleanly', () => {
    const seen: string[] = [];
    const off = onSessionInvalid((r) => seen.push(r));
    emitSessionInvalid('account_deleted');
    off();
    emitSessionInvalid('unauthorized');
    expect(seen).toEqual(['account_deleted']);
  });

  it('shows the friendly session-expired sentence', () => {
    expect(sessionExpiredMessage()).toBe('Your session has expired. Please log in again.');
  });
});

describe('bootstrapSession (app start)', () => {
  it('goes to Login when no token is stored', async () => {
    const local = fakeLocal();
    local.state.token = null;
    const res = await bootstrapSession({ ...local, fetchMe: async () => activeUser });
    expect(res).toEqual({ kind: 'signed_out', reason: 'no_token' });
  });

  it('is ready with the user for a valid token', async () => {
    const res = await bootstrapSession({ ...fakeLocal(), fetchMe: async () => activeUser });
    expect(res).toEqual({ kind: 'ready', user: activeUser });
  });

  it('401 from /me clears the token and returns to Login', async () => {
    const local = fakeLocal();
    const res = await bootstrapSession({ ...local, fetchMe: async () => { throw { status: 401, message: 'Unauthenticated.' }; } });
    expect(res).toEqual({ kind: 'signed_out', reason: 'session_invalid' });
    expect(local.state.cleared).toBe(1);
    expect(local.state.token).toBeNull();
  });

  it('ACCOUNT_DELETED from /me clears the token and returns to Login', async () => {
    const local = fakeLocal();
    const res = await bootstrapSession({ ...local, fetchMe: async () => { throw { status: 401, code: 'account_deleted' }; } });
    expect(res.kind).toBe('signed_out');
    expect(local.state.token).toBeNull();
  });

  it('500 / network from /me keeps the token and asks for Retry + Logout', async () => {
    const local = fakeLocal();
    const server = await bootstrapSession({ ...local, fetchMe: async () => { throw { status: 500, message: 'Server Error' }; } });
    expect(server.kind).toBe('recoverable');
    const offline = await bootstrapSession({ ...local, fetchMe: async () => { throw new TypeError('Network request failed'); } });
    expect(offline.kind).toBe('recoverable');
    expect(local.state.cleared).toBe(0);
    expect(local.state.token).toBe('tok');
  });

  it('routes a ready user by verification and sport', () => {
    expect(routeForUser(activeUser)).toBe('Home');
    expect(routeForUser({ ...activeUser, preferred_sport: null })).toBe('SportSelection');
    expect(routeForUser({ ...activeUser, email_verified: false })).toBe('EmailVerification');
  });
});

describe('logoutLocally', () => {
  it('clears local auth even when the API logout fails', async () => {
    const local = fakeLocal();
    await expect(logoutLocally({
      apiLogout: async () => { throw { status: 401, message: 'Unauthenticated.' }; },
      unregisterPush: async () => { throw new Error('no push'); },
      clearLocal: local.clearLocal,
    })).resolves.toBeUndefined();
    expect(local.state.cleared).toBe(1);
    expect(local.state.token).toBeNull();
  });

  it('clears local auth when offline', async () => {
    const local = fakeLocal();
    await logoutLocally({ apiLogout: async () => { throw new TypeError('Network request failed'); }, clearLocal: local.clearLocal });
    expect(local.state.token).toBeNull();
  });

  it('calls the server first, then wipes locally', async () => {
    const order: string[] = [];
    await logoutLocally({
      unregisterPush: async () => { order.push('push'); },
      apiLogout: async () => { order.push('api'); },
      clearLocal: async () => { order.push('local'); },
    });
    expect(order).toEqual(['push', 'api', 'local']);
  });
});

describe('deleteAccountAndSignOut', () => {
  it('clears the local token and returns to Login after a successful delete', async () => {
    const local = fakeLocal();
    const res = await deleteAccountAndSignOut({ deleteAccount: async () => ({ deleted: true }), clearLocal: local.clearLocal });
    expect(res).toEqual({ outcome: 'deleted' });
    expect(local.state.token).toBeNull();
  });

  it('treats a dead session as already gone and still signs out locally', async () => {
    const local = fakeLocal();
    const res = await deleteAccountAndSignOut({ deleteAccount: async () => { throw { status: 401, code: 'session_invalid' }; }, clearLocal: local.clearLocal });
    expect(res).toEqual({ outcome: 'session_gone' });
    expect(local.state.token).toBeNull();
  });

  it('keeps the session when the server refuses (admin account / 5xx)', async () => {
    const local = fakeLocal();
    const admin = await deleteAccountAndSignOut({ deleteAccount: async () => { throw { status: 403, code: 'admin_account_protected' }; }, clearLocal: local.clearLocal });
    expect(admin.outcome).toBe('failed');
    const server = await deleteAccountAndSignOut({ deleteAccount: async () => { throw { status: 500 }; }, clearLocal: local.clearLocal });
    expect(server.outcome).toBe('failed');
    expect(local.state.token).toBe('tok');
  });
});

describe('Home identity header', () => {
  it('shows the real name and handle once loaded', () => {
    expect(homeIdentity('ready', { name: 'Alice', username: 'alice' })).toEqual({ kind: 'ready', greeting: 'Hey, Alice', handle: '@alice' });
  });

  it('says loading while loading, never "Hey, …"', () => {
    const v = homeIdentity('loading', null);
    expect(v.kind).toBe('loading');
    expect(JSON.stringify(v)).not.toContain('Hey,');
  });

  it('offers Retry + Logout when the profile could not load — "Hey, …" is never a final state', () => {
    const failed = homeIdentity('failed', null);
    expect(failed).toEqual({ kind: 'failed', message: "Couldn't load your profile.", retryLabel: 'Retry', logoutLabel: 'Log out' });
    const readyWithoutUser = homeIdentity('ready', null);
    expect(readyWithoutUser.kind).toBe('failed');
    expect(JSON.stringify(readyWithoutUser)).not.toContain('…');
  });

  it('HomeScreen no longer renders a "…" placeholder for the name or handle', () => {
    const source = fs.readFileSync(path.join(SRC, 'screens', 'HomeScreen.tsx'), 'utf8');
    expect(source).not.toMatch(/\|\|\s*'…'/);
    expect(source).toMatch(/homeIdentity\(/);
  });
});

describe('parseJsonBody', () => {
  it('parses a body that starts with a UTF-8 byte-order mark', () => {
    // A PHP file saved with a BOM makes an un-cached backend send this for EVERY response.
    expect(parseJsonBody('﻿{"data":{"id":1}}')).toEqual({ data: { id: 1 } });
    expect(() => JSON.parse('﻿{}')).toThrow(); // what the app did before
  });

  it('treats an empty body as null and rejects non-JSON', () => {
    expect(parseJsonBody('')).toBeNull();
    expect(parseJsonBody('  \n')).toBeNull();
    expect(() => parseJsonBody('<!doctype html><html>')).toThrow();
  });

  it('the API client reads bodies through it and never through response.json()', () => {
    const client = fs.readFileSync(path.join(SRC, 'api', 'client.ts'), 'utf8');
    expect(client).toMatch(/parseJsonBody\(/);
    expect(client).not.toMatch(/response\.json\(\)/);
    expect(fs.readFileSync(path.join(SRC, 'api', 'authApi.ts'), 'utf8')).toMatch(/\.then\(parseProfile\)/);
  });
});

describe('parseProfile (the /me body)', () => {
  const full = {
    id: 7, name: 'Alice', username: 'alice', email: 'a@example.com', email_verified: true,
    selected_theme: 'classic', preferred_language: 'nl', two_factor_enabled: true,
    avatar_url: 'https://x/a.jpg', preferred_sport: { id: 1, slug: 'football', name: 'Football', emoji: '⚽' },
  };

  it('handles a successful, complete /me (wrapped or bare)', () => {
    expect(parseProfile({ data: full })).toEqual(full);
    expect(parseProfile(full)).toEqual(full);
  });

  it('fills safe defaults for missing optional fields (older backend / NULL columns)', () => {
    const user = parseProfile({ data: { id: '7', username: 'alice' } });
    expect(user).toEqual({
      id: 7, name: 'alice', username: 'alice', email: undefined, email_verified: true,
      selected_theme: 'pitch_green', preferred_language: 'en', two_factor_enabled: false,
      avatar_url: null, preferred_sport: null,
    });
    expect(parseProfile({ data: { ...full, selected_theme: null, preferred_language: '', two_factor_enabled: 'yes', avatar_url: '', preferred_sport: {} } })).toMatchObject({
      selected_theme: 'pitch_green', preferred_language: 'en', two_factor_enabled: false, avatar_url: null, preferred_sport: null,
    });
  });

  it('rejects an unknown or malformed body with a stable code instead of crashing', () => {
    for (const bad of [null, undefined, 'ok', 42, [], {}, { data: null }, { data: { id: 0, username: 'x' } }, { data: { id: 3 } }, { data: { username: 'x' } }]) {
      let caught: any = null;
      try { parseProfile(bad); } catch (e) { caught = e; }
      expect(caught?.code).toBe(MALFORMED_PROFILE);
      expect(typeof caught?.reason).toBe('string');
      expect(JSON.stringify(caught)).not.toContain('Alice');
    }
  });
});

describe('describeApiFailure (safe dev/diagnostic summary)', () => {
  it('captures endpoint, status and stable code — never the message', () => {
    const s = describeApiFailure({ status: 500, code: 'profile_unavailable', message: 'SQLSTATE secret' }, 'GET /me');
    expect(s).toEqual({ endpoint: 'GET /me', status: 500, code: 'profile_unavailable', kind: 'recoverable' });
    expect(formatApiFailure(s)).toBe('[api] GET /me -> 500 profile_unavailable (recoverable)');
    expect(formatApiFailure(s)).not.toContain('SQLSTATE');
  });

  it('classifies network, dead-session and server failures', () => {
    expect(describeApiFailure(new TypeError('Network request failed'), 'GET /me')).toMatchObject({ status: null, code: null, kind: 'network' });
    expect(describeApiFailure({ status: 401, code: 'session_invalid' }, 'GET /me').kind).toBe('invalid');
    expect(describeApiFailure({ status: 401, code: 'account_deleted' }, 'GET /me').kind).toBe('invalid');
    expect(describeApiFailure({ status: 503 }, 'GET /profile/stats').kind).toBe('recoverable');
  });
});

describe('resolveProfileLoad (Profile view model)', () => {
  const full = { id: 7, name: 'Alice', username: 'alice', preferred_sport: null };

  it('is ready with the parsed user on a successful /me', () => {
    const out = resolveProfileLoad({ ok: true, raw: { data: full } });
    expect(out.kind).toBe('ready');
    if (out.kind === 'ready') expect(out.user.name).toBe('Alice');
  });

  it('is ready with defaults when optional fields are missing', () => {
    const out = resolveProfileLoad({ ok: true, raw: { data: { id: 7, username: 'alice' } } });
    expect(out.kind).toBe('ready');
    if (out.kind === 'ready') expect(out.user).toMatchObject({ name: 'alice', selected_theme: 'pitch_green', preferred_language: 'en', two_factor_enabled: false });
  });

  it('401 / account_deleted / session_invalid → session invalid (log out)', () => {
    for (const error of [{ status: 401, message: 'Unauthenticated.' }, { status: 401, code: 'account_deleted' }, { status: 403, code: 'session_invalid' }]) {
      const out = resolveProfileLoad({ ok: false, error });
      expect(out.kind).toBe('session_invalid');
    }
  });

  it('500 / profile_unavailable / network → stay with Retry + Logout', () => {
    for (const error of [{ status: 500, message: 'Server Error' }, { status: 500, code: 'profile_unavailable' }, new TypeError('Network request failed'), { status: 429, retry_after: 30 }]) {
      const out = resolveProfileLoad({ ok: false, error });
      expect(out.kind).toBe('failed');
      if (out.kind === 'failed') {
        expect(out.view.actions.map((a) => a.id)).toEqual(['retry', 'logout']);
        expect(out.view.title).toBe("Couldn't load your profile");
      }
    }
  });

  it('does not crash on an unknown or malformed 200 body — it becomes a recoverable failure', () => {
    for (const raw of [null, 'html', { data: {} }, { data: { id: 'abc', username: '' } }, [1, 2]]) {
      const out = resolveProfileLoad({ ok: true, raw });
      expect(out.kind).toBe('failed');
      if (out.kind === 'failed') {
        expect(out.failure).toMatchObject({ endpoint: 'GET /me', status: 200, code: MALFORMED_PROFILE, kind: 'recoverable' });
        expect(out.view.actions.map((a) => a.id)).toEqual(['retry', 'logout']);
      }
    }
  });
});

describe('Profile error state', () => {
  it('renders Retry and Logout for a recoverable failure', () => {
    const view = profileLoadFailureView('recoverable');
    expect(view.title).toBe("Couldn't load your profile");
    expect(view.message).toBe('Check your connection and try again.');
    expect(view.actions.map((a) => a.id)).toEqual(['retry', 'logout']);
    expect(view.actions.map((a) => a.label)).toEqual(['Retry', 'Log out']);
  });

  it('always includes Logout, even when the session is invalid', () => {
    const view = profileLoadFailureView('invalid');
    expect(view.message).toBe('Your session has expired. Please log in again.');
    expect(view.actions.map((a) => a.id)).toEqual(['logout']);
  });

  it('ProfileScreen wires the error state through profileLoadFailureView and logs out via logoutLocally', () => {
    const source = fs.readFileSync(path.join(SRC, 'screens', 'ProfileScreen.tsx'), 'utf8');
    expect(source).toMatch(/profileLoadFailureView\(/);
    expect(source).toMatch(/logoutLocally\(/);
    expect(source).toMatch(/deleteAccountAndSignOut\(/);
  });

  it('the navigator validates the stored token through bootstrapSession and listens for dead sessions', () => {
    const source = fs.readFileSync(path.join(SRC, 'app', 'AppNavigator.tsx'), 'utf8');
    expect(source).toMatch(/bootstrapSession\(/);
    expect(source).toMatch(/onSessionInvalid\(/);
    const client = fs.readFileSync(path.join(SRC, 'api', 'client.ts'), 'utf8');
    expect(client).toMatch(/isSessionInvalidResponse\(/);
    expect(client).toMatch(/emitSessionInvalid\(/);
  });
});
