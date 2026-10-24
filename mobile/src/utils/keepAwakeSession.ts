/**
 * Keep-the-screen-awake session for active gameplay (pure TS, jest-tested).
 *
 * A guess can take a while — zooming into a photo, looking for the ball —
 * and the phone must not dim or lock in the middle of it. Gameplay screens
 * hold a keep-awake lock while they are FOCUSED and release it the moment
 * they blur or unmount (result screen, leaving the flow, app navigation).
 *
 * The rules this module pins:
 *  - activate once per session, with a per-screen tag (so two screens never
 *    release each other's lock);
 *  - stop() always releases, even if it runs before activation resolved
 *    (fast replace / quick back) — the late activation is undone at once;
 *  - native failures (unsupported device, web without Wake Lock) are
 *    swallowed: keep-awake is a comfort feature and must never break a game.
 */
export interface KeepAwakeApi {
  activate: (tag: string) => Promise<void>;
  deactivate: (tag: string) => Promise<void> | void;
}

export interface KeepAwakeSession {
  stop: () => void;
}

function safeDeactivate(api: KeepAwakeApi, tag: string): void {
  try {
    const p = api.deactivate(tag);
    if (p && typeof (p as Promise<void>).catch === 'function') (p as Promise<void>).catch(() => {});
  } catch {
    // best-effort
  }
}

export function startKeepAwakeSession(api: KeepAwakeApi, tag: string): KeepAwakeSession {
  let stopped = false;
  let active = false;

  try {
    api.activate(tag).then(
      () => {
        active = true;
        // Stopped while activation was in flight: release immediately.
        if (stopped) { active = false; safeDeactivate(api, tag); }
      },
      () => { /* not supported here — nothing to release */ },
    );
  } catch {
    // synchronous native failure — nothing to release
  }

  return {
    stop: () => {
      if (stopped) return;
      stopped = true;
      if (active) { active = false; safeDeactivate(api, tag); }
    },
  };
}

/** Tags used by the gameplay screens (one per screen). */
export const KEEP_AWAKE_TAGS = {
  daily: 'bp-gameplay-daily',
  tournament: 'bp-gameplay-tournament',
  pack: 'bp-gameplay-pack',
} as const;
