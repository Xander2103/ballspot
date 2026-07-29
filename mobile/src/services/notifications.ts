import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { notificationsApi, type NotificationSettings } from '../api/notificationsApi';

export type PermissionStatus = 'granted' | 'denied' | 'undetermined' | 'unsupported';

const COPY = {
  daily: {
    title: 'Daily Ball Challenge',
    body: 'Your challenge is waiting. Make your guess before the day ends.',
  },
  tournament: {
    title: 'Tournament waiting',
    body: 'Your friends are waiting for your next guess.',
  },
};

let handlerConfigured = false;

/** Web has no local-notification support in Expo — everything here no-ops there. */
function isSupported(): boolean {
  return Platform.OS !== 'web';
}

function mapStatus(status: string): PermissionStatus {
  if (status === 'granted') return 'granted';
  if (status === 'denied') return 'denied';
  return 'undetermined';
}

/**
 * Parse a stored "HH:mm" into a valid {hour, minute}, always returning numbers
 * in range (never NaN) so the scheduler and any UI can rely on it. Falls back
 * to 19:00 for anything malformed.
 */
export function parseReminderTime(time: string | undefined | null): { hour: number; minute: number } {
  const match = /^(\d{1,2}):(\d{2})$/.exec((time ?? '').trim());
  if (!match) return { hour: 19, minute: 0 };
  const hour = Math.min(23, Math.max(0, Number(match[1])));
  const minute = Math.min(59, Math.max(0, Number(match[2])));
  if (Number.isNaN(hour) || Number.isNaN(minute)) return { hour: 19, minute: 0 };
  return { hour, minute };
}

/** True for a well-formed 24-hour HH:mm string. */
export function isValidReminderTime(time: string): boolean {
  return /^([01]?\d|2[0-3]):[0-5]\d$/.test(time.trim());
}

function configureHandler(): void {
  if (handlerConfigured || !isSupported()) return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: false,
    }),
  });
  handlerConfigured = true;
}

async function getPermissionStatus(): Promise<PermissionStatus> {
  if (!isSupported()) return 'unsupported';
  try {
    const { status } = await Notifications.getPermissionsAsync();
    return mapStatus(status);
  } catch {
    return 'unsupported';
  }
}

async function requestPermission(): Promise<PermissionStatus> {
  if (!isSupported()) return 'unsupported';
  try {
    const { status } = await Notifications.requestPermissionsAsync();
    return mapStatus(status);
  } catch {
    return 'unsupported';
  }
}

/**
 * Best-effort Expo push token registration for admin announcements. Requires
 * granted permission and (in a real build) an EAS projectId. If either is
 * missing or the device is offline it silently skips — local reminders are
 * unaffected.
 */
async function registerPushToken(): Promise<void> {
  if (!isSupported()) return;
  try {
    if ((await getPermissionStatus()) !== 'granted') return;
    const { data: token } = await Notifications.getExpoPushTokenAsync();
    const platform = Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web';
    await notificationsApi.registerPushToken({ token, platform });
  } catch {
    // No EAS projectId configured / offline — remote push simply stays off.
  }
}

/**
 * Best-effort removal of this device's push registration on the backend.
 * Called on logout so a signed-out device stops receiving announcements.
 * If the device token cannot be read, nothing is removed (other devices'
 * registrations must survive a single-device logout).
 */
async function unregisterPushToken(): Promise<void> {
  if (!isSupported()) return;
  try {
    const { data: token } = await Notifications.getExpoPushTokenAsync();
    if (token) await notificationsApi.unregisterPushToken(token);
  } catch {
    // Offline / no projectId — the stale row is pruned server-side after 90 days.
  }
}

export interface ScheduleState {
  settings: NotificationSettings;
  /** Today's daily is done (or none exists) — suppress the daily reminder. */
  dailyCompleted: boolean;
  /** The user has an outstanding tournament action — enable the reminder. */
  hasPendingTournament: boolean;
}

/**
 * Cancel every reminder this app scheduled, then (re)schedule only the ones the
 * settings + current state call for. These are the ONLY local notifications the
 * app schedules, so a full cancel keeps on-device state exactly in sync with the
 * latest settings (no stale reminders, no duplicates).
 */
async function syncSchedules(state: ScheduleState): Promise<void> {
  if (!isSupported()) return;
  if ((await getPermissionStatus()) !== 'granted') return;

  const { hour, minute } = parseReminderTime(state.settings.reminder_time);
  const daily = { type: Notifications.SchedulableTriggerInputTypes.DAILY, hour, minute } as const;

  try {
    await Notifications.cancelAllScheduledNotificationsAsync();

    if (state.settings.daily_reminder_enabled && !state.dailyCompleted) {
      await Notifications.scheduleNotificationAsync({ content: COPY.daily, trigger: daily });
    }

    if (state.settings.tournament_reminder_enabled && state.hasPendingTournament) {
      await Notifications.scheduleNotificationAsync({ content: COPY.tournament, trigger: daily });
    }
  } catch {
    // Scheduling unavailable on this platform/build — degrade silently.
  }
}

async function cancelAll(): Promise<void> {
  if (!isSupported()) return;
  try {
    await Notifications.cancelAllScheduledNotificationsAsync();
  } catch {
    // ignore
  }
}

export const notifications = {
  isSupported,
  configureHandler,
  getPermissionStatus,
  requestPermission,
  registerPushToken,
  unregisterPushToken,
  syncSchedules,
  cancelAll,
};
