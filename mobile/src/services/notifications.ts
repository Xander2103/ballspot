import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { notificationsApi, type NotificationSettings } from '../api/notificationsApi';
import { translate } from '../i18n/core';
import { localFlags } from '../storage/localFlags';
import { canDeliver, mapDevicePermission, type DevicePermission } from '../utils/notificationPermission';
import { createPushRegistrar } from '../utils/pushRegistration';

/** Holds the last token sent to the backend, so it can be removed without asking the OS. */
const PUSH_TOKEN_FLAG = 'push_token_registered';

/** Android delivers pushes without a channelId through a channel with this id. */
const ANDROID_CHANNEL_ID = 'default';

/**
 * Reminder copy is resolved at schedule time (not module load) so a reminder
 * scheduled after a language change is delivered in the app's language.
 */
function reminderCopy() {
  return {
    daily: {
      title: translate('notifications.reminders.dailyTitle'),
      body: translate('notifications.reminders.dailyBody'),
    },
    tournament: {
      title: translate('notifications.reminders.tournamentTitle'),
      body: translate('notifications.reminders.tournamentBody'),
    },
  };
}

let handlerConfigured = false;

/** Web has no local-notification support in Expo — everything here no-ops there. */
function isSupported(): boolean {
  return Platform.OS !== 'web';
}

let androidChannelReady = false;

/**
 * Android 13+ needs a channel before the permission dialog can appear and before
 * a push token can be read. Created only at those moments, never just by showing
 * the invitation, so "Maybe later" cannot lead to a system dialog.
 */
async function ensureAndroidChannel(): Promise<void> {
  if (Platform.OS !== 'android' || androidChannelReady) return;
  try {
    await Notifications.setNotificationChannelAsync(ANDROID_CHANNEL_ID, {
      name: translate('notifications.channel.name'),
      importance: Notifications.AndroidImportance.HIGH,
    });
    androidChannelReady = true;
  } catch {
    // Retried at the next request or registration.
  }
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

async function getPermissionStatus(): Promise<DevicePermission> {
  if (!isSupported()) return 'unsupported';
  try {
    return mapDevicePermission(await Notifications.getPermissionsAsync());
  } catch {
    return 'unsupported';
  }
}

async function requestPermission(): Promise<DevicePermission> {
  if (!isSupported()) return 'unsupported';
  try {
    await ensureAndroidChannel();
    return mapDevicePermission(await Notifications.requestPermissionsAsync());
  } catch {
    return 'unsupported';
  }
}

async function readDeviceToken(): Promise<string> {
  await ensureAndroidChannel();
  const { data } = await Notifications.getExpoPushTokenAsync();
  return data;
}

const pushRegistrar = createPushRegistrar({
  getPermission: getPermissionStatus,
  readDeviceToken,
  sendRegistration: (token) => notificationsApi.registerPushToken({
    token,
    platform: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web',
  }),
  sendRemoval: (token) => notificationsApi.unregisterPushToken(token),
  loadStoredToken: () => localFlags.get(PUSH_TOKEN_FLAG),
  saveStoredToken: (token) => localFlags.set(PUSH_TOKEN_FLAG, token),
  clearStoredToken: () => localFlags.remove(PUSH_TOKEN_FLAG),
  cancelLocalReminders: () => cancelAll(),
});

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
  if (!canDeliver(await getPermissionStatus())) return;
  await ensureAndroidChannel();

  const { hour, minute } = parseReminderTime(state.settings.reminder_time);
  const daily = {
    type: Notifications.SchedulableTriggerInputTypes.DAILY,
    hour,
    minute,
    ...(Platform.OS === 'android' ? { channelId: ANDROID_CHANNEL_ID } : {}),
  } as const;
  const copy = reminderCopy();

  try {
    await Notifications.cancelAllScheduledNotificationsAsync();

    // When the server owns daily reminders (backend push active), never
    // schedule the local daily reminder too — the user would be notified twice.
    const serverOwnsDaily = state.settings.daily_reminder_push_active === true;
    if (state.settings.daily_reminder_enabled && !state.dailyCompleted && !serverOwnsDaily) {
      await Notifications.scheduleNotificationAsync({ content: copy.daily, trigger: daily });
    }

    if (state.settings.tournament_reminder_enabled && state.hasPendingTournament) {
      await Notifications.scheduleNotificationAsync({ content: copy.tournament, trigger: daily });
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
  /** Applies the current OS permission to the push registration and reminders, and returns it. */
  reconcile: pushRegistrar.reconcile,
  unregisterPushToken: pushRegistrar.unregisterForLogout,
  forgetDevice: pushRegistrar.forgetDevice,
  syncSchedules,
  cancelAll,
};
