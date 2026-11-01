/** Local reminder copy (scheduled on-device) and the notification settings card. */
export const notifications = {
  channel: {
    name: 'Reminders',
  },
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
  unsupported: 'Reminders are delivered in the mobile app. Your preferences here still sync to your phone.',
  device: {
    label: 'Notifications on this device',
    granted: 'Allowed',
    grantedHint: 'This device can show BallPicker notifications.',
    provisional: 'Allowed quietly',
    provisionalHint: 'Notifications go straight to Notification Center, without a banner or sound. You can change this in your device settings.',
    denied: 'Blocked',
    deniedHint: 'Notifications are blocked in your device settings. Your choices below are saved and apply as soon as you allow them.',
    notDetermined: 'Not turned on yet',
    notDeterminedHint: 'Your choices below are saved and apply as soon as you allow notifications.',
  },
  delivery: {
    blocked: 'On, but blocked by your device',
    notEnabled: 'On, but notifications are not allowed yet',
  },
  openSettings: 'Open settings',
  enable: 'Allow notifications',
  },
};
