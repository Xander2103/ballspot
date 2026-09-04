import {
  prepareForNewAccount,
  adoptToken,
  resolveVerificationTarget,
  normalizeCode,
  buildVerifyPayload,
  classifyVerificationError,
  routeAfterVerification,
  resendNotice,
  TokenStore,
} from '../verificationFlow';

function memoryStore(initial: string | null = null): TokenStore & { value: string | null } {
  const s = {
    value: initial,
    async get() { return s.value; },
    async save(t: string) { s.value = t; },
    async remove() { s.value = null; },
  };
  return s;
}

describe('auth state handover when registering a new account', () => {
  it('clears the previous account token and local state before registering', async () => {
    const store = memoryStore('old-account-token');
    const cleared: string[] = [];

    await prepareForNewAccount(store, async () => { cleared.push('local'); });

    expect(store.value).toBeNull();
    expect(cleared).toEqual(['local']);
  });

  it('still drops the token when local cleanup throws', async () => {
    const store = memoryStore('old-account-token');

    await prepareForNewAccount(store, async () => { throw new Error('boom'); });

    expect(store.value).toBeNull();
  });

  it('adopts the newly issued token, replacing the old one', async () => {
    const store = memoryStore('old-account-token');

    await adoptToken(store, 'new-account-token');

    expect(store.value).toBe('new-account-token');
  });

  it('refuses to continue without a token or when storage silently fails', async () => {
    await expect(adoptToken(memoryStore(), '')).rejects.toThrow('did not return a session token');

    const broken: TokenStore = { async get() { return null; }, async save() {}, async remove() {} };
    await expect(adoptToken(broken, 'tok')).rejects.toThrow('Could not store the session');
  });
});

describe('resolveVerificationTarget', () => {
  it('shows the token account email, not the navigation param', () => {
    expect(resolveVerificationTarget('typed@example.com', 'typed@example.com')).toEqual({ email: 'typed@example.com', mismatch: false });
  });

  it('is case-insensitive and ignores whitespace', () => {
    expect(resolveVerificationTarget(' Typed@Example.com ', 'typed@example.com').mismatch).toBe(false);
  });

  it('flags a stale session when the token belongs to a different account', () => {
    expect(resolveVerificationTarget('new@example.com', 'previous@example.com')).toEqual({ email: 'previous@example.com', mismatch: true });
  });

  it('falls back to whichever side is known', () => {
    expect(resolveVerificationTarget('new@example.com', null)).toEqual({ email: 'new@example.com', mismatch: false });
    expect(resolveVerificationTarget(undefined, 'me@example.com')).toEqual({ email: 'me@example.com', mismatch: false });
    expect(resolveVerificationTarget(undefined, undefined)).toEqual({ email: null, mismatch: false });
  });
});

describe('verify request payload', () => {
  it('trims and strips non-digits from the code', () => {
    expect(normalizeCode(' 123 456\n')).toBe('123456');
    expect(normalizeCode('12-34-56')).toBe('123456');
  });

  it('rejects anything that is not exactly six digits', () => {
    expect(normalizeCode('12345')).toBeNull();
    expect(normalizeCode('1234567')).toBeNull();
    expect(normalizeCode('')).toBeNull();
  });

  it('sends the code with the target email as a mismatch hint', () => {
    expect(buildVerifyPayload(' 012345 ', 'new@example.com')).toEqual({ code: '012345', email: 'new@example.com' });
    expect(buildVerifyPayload('012345', null)).toEqual({ code: '012345' });
    expect(buildVerifyPayload('12', 'new@example.com')).toBeNull();
  });
});

describe('classifyVerificationError', () => {
  it('maps each backend reason to a specific friendly message', () => {
    expect(classifyVerificationError({ status: 422, reason: 'wrong_code', message: 'Invalid or expired verification code.' }).kind).toBe('wrong_code');
    expect(classifyVerificationError({ status: 422, reason: 'expired' }).kind).toBe('expired');
    expect(classifyVerificationError({ status: 422, reason: 'locked' }).message).toContain('Resend code');
    expect(classifyVerificationError({ status: 422, reason: 'no_code' }).kind).toBe('no_code');
  });

  it('recognises a session mismatch (409) and a dead session (401)', () => {
    expect(classifyVerificationError({ status: 409, reason: 'session_mismatch' }).kind).toBe('session_mismatch');
    expect(classifyVerificationError({ status: 401, message: 'Unauthenticated.' })).toEqual({
      kind: 'unauthorized',
      message: 'Your session has expired. Please log in again to continue verifying.',
    });
  });

  it('never shows raw server text for unknown failures', () => {
    const out = classifyVerificationError({ status: 500, message: 'Server Error' });
    expect(out.kind).toBe('other');
    expect(out.message).not.toContain('Server Error');

    const validation = classifyVerificationError({ status: 422, message: 'The given data was invalid.', errors: { code: ['Enter the 6-digit code from your email.'] } });
    expect(validation.message).toBe('Enter the 6-digit code from your email.');
  });
});

describe('after verification', () => {
  it('routes to Home when a sport is chosen, else onboarding', () => {
    expect(routeAfterVerification({ preferred_sport: { id: 1 } })).toBe('Home');
    expect(routeAfterVerification({ preferred_sport: null })).toBe('SportSelection');
    expect(routeAfterVerification(null)).toBe('SportSelection');
  });

  it('resend notice names the account the code went to and keeps old codes valid', () => {
    expect(resendNotice('new@example.com')).toBe('A new code has been sent to new@example.com. Codes from earlier emails still work too.');
    expect(resendNotice(null)).toContain('to your email');
  });
});
