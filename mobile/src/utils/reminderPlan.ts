/**
 * When the LOCAL daily reminder should fire.
 *
 * The old schedule was one repeating DAILY trigger that Home cancelled as soon
 * as today's daily was played. Nothing rescheduled it until the app was opened
 * again on a later day, so a player who finished the daily got no reminder the
 * next day unless they opened the app before the reminder time themselves:
 * exactly the players a reminder is for.
 *
 * Now each day is its own one-off notification for the next DAYS_AHEAD days.
 * Today is skipped once it is handled (played, or no daily), later days stay.
 * Every Home focus cancels and rebuilds the list, so it always reaches a week
 * ahead of the last app open, and it stops after a week of inactivity
 * rather than nagging an inactive player forever.
 */
export const DAILY_REMINDER_DAYS_AHEAD = 7;

export function planDailyReminderDates(
  now: Date,
  hour: number,
  minute: number,
  todayHandled: boolean,
  daysAhead: number = DAILY_REMINDER_DAYS_AHEAD,
): Date[] {
  const dates: Date[] = [];
  for (let offset = 0; offset < daysAhead; offset++) {
    if (offset === 0 && todayHandled) continue;
    // Local wall-clock time; the Date constructor rolls month/year over and
    // keeps the hour across DST changes.
    const at = new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, hour, minute, 0, 0);
    if (at.getTime() <= now.getTime()) continue;
    dates.push(at);
  }
  return dates;
}
