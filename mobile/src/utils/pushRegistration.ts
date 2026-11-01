import { canDeliver, type DevicePermission } from './notificationPermission';

export interface PushRegistrarDeps {
  getPermission: () => Promise<DevicePermission>;
  readDeviceToken: () => Promise<string>;
  sendRegistration: (token: string) => Promise<unknown>;
  sendRemoval: (token: string) => Promise<unknown>;
  loadStoredToken: () => Promise<string | null>;
  saveStoredToken: (token: string) => Promise<void>;
  clearStoredToken: () => Promise<void>;
  cancelLocalReminders: () => Promise<void>;
}

/**
 * Keeps the backend's push registration and the local reminders in line with
 * what the OS allows. This is the only code that registers or removes this
 * device's token. Everything is best effort: a failed call is retried on the
 * next reconcile and never reaches the UI.
 */
export function createPushRegistrar(deps: PushRegistrarDeps) {
  // Registered once per app run, which also refreshes the backend's last_seen_at.
  let registeredThisRun = false;
  let inFlight: Promise<DevicePermission> | null = null;

  async function register(): Promise<void> {
    if (registeredThisRun) return;
    try {
      const token = await deps.readDeviceToken();
      await deps.sendRegistration(token);
      const previous = await deps.loadStoredToken();
      await deps.saveStoredToken(token);
      registeredThisRun = true;
      if (previous && previous !== token) await deps.sendRemoval(previous);
    } catch {
      // No projectId, offline, or the token store failed: retried next time.
    }
  }

  async function removeStoredRegistration(): Promise<void> {
    registeredThisRun = false;
    try {
      const stored = await deps.loadStoredToken();
      if (!stored) return;
      await deps.sendRemoval(stored);
      await deps.clearStoredToken();
    } catch {
      // Offline: the stored token stays, so the removal is retried next time.
    }
  }

  async function run(): Promise<DevicePermission> {
    const permission = await deps.getPermission();
    if (canDeliver(permission)) {
      await register();
    } else if (permission !== 'unsupported') {
      await removeStoredRegistration();
      try { await deps.cancelLocalReminders(); } catch { /* best effort */ }
    }
    return permission;
  }

  return {
    /** Reads the OS permission, applies it, and returns it. */
    reconcile(): Promise<DevicePermission> {
      // Focus and foreground events can overlap: they share one run.
      if (!inFlight) inFlight = run().finally(() => { inFlight = null; });
      return inFlight;
    },

    /**
     * Logout, while the auth token still works. Only this device's token goes:
     * the account's other devices must keep their registration.
     */
    async unregisterForLogout(): Promise<void> {
      registeredThisRun = false;
      try {
        const token = (await deps.loadStoredToken()) ?? (await deps.readDeviceToken());
        if (token) await deps.sendRemoval(token);
      } catch {
        // Offline or no projectId: the backend prunes stale rows after 90 days.
      }
    },

    /** Local sign-out: the next account on this device registers from scratch. */
    async forgetDevice(): Promise<void> {
      registeredThisRun = false;
      try { await deps.clearStoredToken(); } catch { /* best effort */ }
    },
  };
}
