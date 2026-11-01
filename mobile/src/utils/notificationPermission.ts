/**
 * Notification permission and invitation rules. Pure TypeScript (no React
 * Native, no Expo), so every rule runs under the plain jest runner.
 *
 * The operating system is the only source of truth for what the device allows.
 * The server settings are the user's preferences: they say what the user wants,
 * never what is actually delivered.
 */

export type DevicePermission = 'notDetermined' | 'granted' | 'provisional' | 'denied' | 'unsupported';

/** The fields of expo-notifications' NotificationPermissionsStatus used here. */
export interface RawPermissionResponse {
  status?: string | null;
  ios?: { status?: number | null } | null;
}

// Values of expo-notifications' IosAuthorizationStatus.
const IOS_NOT_DETERMINED = 0;
const IOS_DENIED = 1;
const IOS_AUTHORIZED = 2;
const IOS_PROVISIONAL = 3;
const IOS_EPHEMERAL = 4;

/**
 * On iOS the root `status` reports provisional as undetermined, so `ios.status`
 * decides there (as the Expo docs instruct). Ephemeral is a limited, temporary
 * grant, so it counts as provisional.
 */
export function mapDevicePermission(raw: RawPermissionResponse | null | undefined): DevicePermission {
  switch (raw?.ios?.status) {
    case IOS_NOT_DETERMINED: return 'notDetermined';
    case IOS_DENIED: return 'denied';
    case IOS_AUTHORIZED: return 'granted';
    case IOS_PROVISIONAL:
    case IOS_EPHEMERAL: return 'provisional';
  }
  if (raw?.status === 'granted') return 'granted';
  if (raw?.status === 'denied') return 'denied';
  return 'notDetermined';
}

/** True when the OS will deliver notifications from this app. */
export function canDeliver(permission: DevicePermission): boolean {
  return permission === 'granted' || permission === 'provisional';
}

export type ReminderDelivery =
  /** On, and the device delivers it. */
  | 'active'
  /** The user switched this reminder off. */
  | 'off'
  /** On, but blocked in the device settings. */
  | 'blocked'
  /** On, but the user has not allowed notifications yet. */
  | 'notEnabled'
  /** On, on a platform without notifications (web). */
  | 'unsupported';

/** What really happens with one reminder: its preference combined with the OS. */
export function reminderDelivery(preference: boolean, permission: DevicePermission): ReminderDelivery {
  if (!preference) return 'off';
  if (canDeliver(permission)) return 'active';
  if (permission === 'denied') return 'blocked';
  if (permission === 'notDetermined') return 'notEnabled';
  return 'unsupported';
}

export const MAX_INVITES = 2;
export const REINVITE_DELAY_MS = 7 * 24 * 60 * 60 * 1000;

export type InviteOutcome =
  /** Shown and put off ("Maybe later", or the app closed with it open). */
  | 'deferred'
  /** The user accepted, so the OS dialog ran or the OS settings opened. */
  | 'accepted';

export interface InviteState {
  count: number;
  lastAt: number | null;
  lastOutcome: InviteOutcome | null;
}

export const NO_INVITES: InviteState = { count: 0, lastAt: null, lastOutcome: null };

/** Stops inviting: an unreadable record must never lead to nagging. */
const EXHAUSTED: InviteState = { count: MAX_INVITES, lastAt: null, lastOutcome: null };

export function parseInviteState(raw: string | null | undefined): InviteState {
  if (!raw) return NO_INVITES;
  try {
    const parsed = JSON.parse(raw);
    if (typeof parsed?.count !== 'number' || parsed.count < 0) return EXHAUSTED;
    return {
      count: parsed.count,
      lastAt: typeof parsed.lastAt === 'number' ? parsed.lastAt : null,
      lastOutcome: parsed.lastOutcome === 'deferred' || parsed.lastOutcome === 'accepted' ? parsed.lastOutcome : null,
    };
  } catch {
    return EXHAUSTED;
  }
}

export function serializeInviteState(state: InviteState): string {
  return JSON.stringify(state);
}

/**
 * Builds on the old one-time `notif_prompt_seen` flag, which held no date or
 * answer. Still undetermined means the user tapped "Not now", so they get one
 * more invitation, seven days from now. Anything else means the OS dialog ran.
 */
export function migrateLegacyPrompt(permission: DevicePermission, now: number): InviteState {
  return {
    count: 1,
    lastAt: now,
    lastOutcome: permission === 'notDetermined' ? 'deferred' : 'accepted',
  };
}

/** Counted when shown, so closing the app on the invitation still uses it up. */
export function markInviteShown(state: InviteState, now: number): InviteState {
  return { count: state.count + 1, lastAt: now, lastOutcome: 'deferred' };
}

export function markInviteAccepted(state: InviteState): InviteState {
  return { ...state, lastOutcome: 'accepted' };
}

export type InviteDecision =
  | 'none'
  /** Explain, then show the OS permission dialog. */
  | 'requestPermission'
  /** The OS will not ask again: explain, then open the OS settings. */
  | 'openSettings';

/**
 * - Allowed, or no notifications on this platform: never invite.
 * - First invitation: only while the OS can still ask. Someone already denied at
 *   OS level gets no unasked pop-up; Settings explains how to turn it on.
 * - Second and last: only after "Maybe later", and at least seven days later.
 */
export function decideInvite(state: InviteState, permission: DevicePermission, now: number): InviteDecision {
  if (permission === 'unsupported' || canDeliver(permission)) return 'none';
  if (state.count >= MAX_INVITES) return 'none';

  if (state.count === 0) {
    return permission === 'notDetermined' ? 'requestPermission' : 'none';
  }

  if (state.lastOutcome !== 'deferred' || state.lastAt === null) return 'none';
  if (now - state.lastAt < REINVITE_DELAY_MS) return 'none';
  return permission === 'denied' ? 'openSettings' : 'requestPermission';
}
