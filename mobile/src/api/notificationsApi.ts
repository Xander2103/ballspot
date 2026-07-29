import { apiClient } from './client';

export interface NotificationSettings {
  daily_reminder_enabled: boolean;
  tournament_reminder_enabled: boolean;
  admin_notifications_enabled: boolean;
  /** 24-hour HH:mm. */
  reminder_time: string;
  timezone: string | null;
}

export type NotificationSettingsUpdate = Partial<NotificationSettings>;

export interface PushTokenRegistration {
  token: string;
  platform?: 'ios' | 'android' | 'web' | null;
  device_name?: string | null;
}

export const notificationsApi = {
  getSettings: () =>
    apiClient.request<NotificationSettings>('/me/notification-settings'),

  updateSettings: (data: NotificationSettingsUpdate) =>
    apiClient.request<NotificationSettings>('/me/notification-settings', {
      method: 'PUT',
      body: JSON.stringify(data),
    }),

  registerPushToken: (data: PushTokenRegistration) =>
    apiClient.request<{ status: string }>('/me/push-tokens', {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  /**
   * Remove push registrations. With a token: only that device's registration.
   * Without: all of the user's registrations (used on account deletion paths).
   */
  unregisterPushToken: (token?: string) =>
    apiClient.request<{ status: string }>('/me/push-tokens', {
      method: 'DELETE',
      body: JSON.stringify(token ? { token } : {}),
    }),
};
