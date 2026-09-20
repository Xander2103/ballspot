/**
 * Development-only diagnostics. Production builds print nothing: the safe
 * categories (endpoint, status, stable code) are logged server-side as
 * `me.failed_*` / `profile.load_failed`; the device only needs them while a
 * developer is watching Metro. Never pass tokens, bodies or user text here.
 */

declare const __DEV__: boolean | undefined;

function isDev(): boolean {
  try {
    return typeof __DEV__ !== 'undefined' && __DEV__ === true;
  } catch {
    return false;
  }
}

export function devLog(message: string): void {
  if (!isDev()) return;
  try {
    // eslint-disable-next-line no-console
    console.warn(message);
  } catch {
    // never let logging break the app
  }
}
