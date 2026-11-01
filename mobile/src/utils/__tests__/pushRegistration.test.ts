import { createPushRegistrar, type PushRegistrarDeps } from '../pushRegistration';
import type { DevicePermission } from '../notificationPermission';

function setup(permission: DevicePermission, stored: string | null = null) {
  const state = { permission, stored };
  const deps = {
    getPermission: jest.fn(async () => state.permission),
    readDeviceToken: jest.fn(async () => 'token-A'),
    sendRegistration: jest.fn(async () => undefined),
    sendRemoval: jest.fn(async () => undefined),
    loadStoredToken: jest.fn(async () => state.stored),
    saveStoredToken: jest.fn(async (token: string) => { state.stored = token; }),
    clearStoredToken: jest.fn(async () => { state.stored = null; }),
    cancelLocalReminders: jest.fn(async () => undefined),
  } satisfies PushRegistrarDeps;
  return { state, deps, registrar: createPushRegistrar(deps) };
}

describe('reconcile', () => {
  it.each<DevicePermission>(['granted', 'provisional'])('registers the token when %s', async (permission) => {
    const { deps, state, registrar } = setup(permission);
    await expect(registrar.reconcile()).resolves.toBe(permission);
    expect(deps.sendRegistration).toHaveBeenCalledWith('token-A');
    expect(state.stored).toBe('token-A');
  });

  it('after "Maybe later" (not determined) it never reads or sends a token', async () => {
    const { deps, registrar } = setup('notDetermined');
    await registrar.reconcile();
    expect(deps.readDeviceToken).not.toHaveBeenCalled();
    expect(deps.sendRegistration).not.toHaveBeenCalled();
  });

  it('registers once per app run, not on every focus', async () => {
    const { deps, registrar } = setup('granted');
    await registrar.reconcile();
    await registrar.reconcile();
    expect(deps.sendRegistration).toHaveBeenCalledTimes(1);
  });

  it('overlapping focus and foreground events share one run', async () => {
    const { deps, registrar } = setup('granted');
    await Promise.all([registrar.reconcile(), registrar.reconcile()]);
    expect(deps.getPermission).toHaveBeenCalledTimes(1);
  });

  it('retries a failed registration on the next reconcile', async () => {
    const { deps, state, registrar } = setup('granted');
    deps.sendRegistration.mockRejectedValueOnce(new Error('offline'));
    await registrar.reconcile();
    expect(state.stored).toBeNull();
    await registrar.reconcile();
    expect(deps.sendRegistration).toHaveBeenCalledTimes(2);
    expect(state.stored).toBe('token-A');
  });

  it('turned off in the OS settings: removes the registration and the local reminders', async () => {
    const { deps, state, registrar } = setup('granted');
    await registrar.reconcile();

    state.permission = 'denied';
    await expect(registrar.reconcile()).resolves.toBe('denied');

    expect(deps.sendRemoval).toHaveBeenCalledWith('token-A');
    expect(state.stored).toBeNull();
    expect(deps.cancelLocalReminders).toHaveBeenCalled();
  });

  it('turned back on in the OS settings: registers again', async () => {
    const { deps, state, registrar } = setup('granted');
    await registrar.reconcile();
    state.permission = 'denied';
    await registrar.reconcile();
    state.permission = 'granted';
    await registrar.reconcile();

    expect(deps.sendRegistration).toHaveBeenCalledTimes(2);
    expect(state.stored).toBe('token-A');
  });

  it('keeps the stored token when a removal fails, to retry it', async () => {
    const { deps, state, registrar } = setup('denied', 'token-A');
    deps.sendRemoval.mockRejectedValueOnce(new Error('offline'));
    await registrar.reconcile();
    expect(state.stored).toBe('token-A');
    await registrar.reconcile();
    expect(state.stored).toBeNull();
  });

  it('removes the old row when the OS issued a new token', async () => {
    const { deps, state, registrar } = setup('granted', 'token-old');
    await registrar.reconcile();
    expect(deps.sendRemoval).toHaveBeenCalledWith('token-old');
    expect(state.stored).toBe('token-A');
  });

  it('touches nothing on a platform without notifications', async () => {
    const { deps, registrar } = setup('unsupported', 'token-A');
    await registrar.reconcile();
    expect(deps.sendRemoval).not.toHaveBeenCalled();
    expect(deps.sendRegistration).not.toHaveBeenCalled();
    expect(deps.cancelLocalReminders).not.toHaveBeenCalled();
  });
});

describe('logout and switching accounts', () => {
  it('removes only this device token, and works without asking the OS', async () => {
    const { deps, registrar } = setup('denied', 'token-A');
    await registrar.unregisterForLogout();
    expect(deps.sendRemoval).toHaveBeenCalledWith('token-A');
    expect(deps.readDeviceToken).not.toHaveBeenCalled();
  });

  it('falls back to the OS token for an install from before the stored token', async () => {
    const { deps, registrar } = setup('granted');
    await registrar.unregisterForLogout();
    expect(deps.sendRemoval).toHaveBeenCalledWith('token-A');
  });

  it('never throws, so a dead network cannot block logging out', async () => {
    const { deps, registrar } = setup('granted', 'token-A');
    deps.sendRemoval.mockRejectedValueOnce(new Error('offline'));
    await expect(registrar.unregisterForLogout()).resolves.toBeUndefined();
  });

  it('the next account registers again in the same app run', async () => {
    const { deps, state, registrar } = setup('granted');
    await registrar.reconcile();

    await registrar.unregisterForLogout();
    await registrar.forgetDevice();
    expect(state.stored).toBeNull();

    await registrar.reconcile();
    expect(deps.sendRegistration).toHaveBeenCalledTimes(2);
    expect(state.stored).toBe('token-A');
  });
});
