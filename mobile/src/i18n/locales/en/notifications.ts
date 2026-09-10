/** Local reminder copy (scheduled on-device) and the notification settings card. */
export const notifications = {
  reminders: {
    dailyTitle: 'Daily Ball Challenge',
    dailyBody: 'Your challenge is waiting. Make your guess before the day ends.',
    tournamentTitle: 'Tournament waiting',
    tournamentBody: 'Your friends are waiting for your next guess.',
  },
  settings: {
    loadError: 'Could not load notification settings.',
    saveError: 'Could not save. Please try again.',
    unavailable: 'Notification settings unavailable.',
    timeInvalid: 'Enter a time as HH:mm (e.g. 19:00).',
    daily: {
      label: 'Daily Challenge reminders',
      hint: 'A nudge when your daily is still waiting.',
    },
    tournament: {
      label: 'Tournament reminders',
      hint: 'When a tournament needs your guess.',
    },
    announcements: {
      label: 'Announcements',
      hint: 'Occasional news from the BallPicker team.',
    },
    reminderTime: 'Reminder time',
    reminderTimeHint: 'When daily / tournament reminders arrive.',
    enabled: '● Notifications enabled',
    unsupported: 'Reminders are delivered in the mobile app. Your preferences here still sync to your phone.',
    denied: 'Notifications are blocked in your device settings. Enable them there to receive reminders.',
    enable: 'Enable notifications',
  },
};
