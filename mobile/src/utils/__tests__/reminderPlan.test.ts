import { DAILY_REMINDER_DAYS_AHEAD, planDailyReminderDates } from '../reminderPlan';

const at = (y: number, m: number, d: number, h = 0, min = 0) => new Date(y, m - 1, d, h, min, 0, 0);
const days = (dates: Date[]) => dates.map((x) => `${x.getMonth() + 1}/${x.getDate()} ${x.getHours()}:${String(x.getMinutes()).padStart(2, '0')}`);

describe('planDailyReminderDates', () => {
  it('a played daily still leaves reminders for the following days (the production bug)', () => {
    const plan = planDailyReminderDates(at(2026, 11, 3, 12), 19, 0, true);
    expect(plan).toHaveLength(DAILY_REMINDER_DAYS_AHEAD - 1);
    expect(days(plan)[0]).toBe('11/4 19:00');
  });

  it('includes today while it is unplayed and the time is still ahead', () => {
    expect(days(planDailyReminderDates(at(2026, 11, 3, 12), 19, 0, false))[0]).toBe('11/3 19:00');
    expect(planDailyReminderDates(at(2026, 11, 3, 12), 19, 0, false)).toHaveLength(7);
  });

  it('never schedules a time that has already passed today', () => {
    const plan = planDailyReminderDates(at(2026, 11, 3, 19, 30), 19, 0, false);
    expect(days(plan)[0]).toBe('11/4 19:00');
    expect(plan.every((d) => d.getTime() > at(2026, 11, 3, 19, 30).getTime())).toBe(true);
  });

  it('one reminder per day, no duplicates, rolling over month and year', () => {
    const plan = planDailyReminderDates(at(2026, 12, 29, 8), 7, 45, true);
    expect(days(plan)).toEqual(['12/30 7:45', '12/31 7:45', '1/1 7:45', '1/2 7:45', '1/3 7:45', '1/4 7:45']);
    expect(new Set(plan.map((d) => d.toDateString())).size).toBe(plan.length);
  });

  it('keeps the wall-clock hour across a DST change', () => {
    // Europe/Brussels switches on the last Sunday of October; the hour must stay 19.
    const plan = planDailyReminderDates(at(2026, 10, 23, 10), 19, 0, false, 7);
    expect(plan.every((d) => d.getHours() === 19 && d.getMinutes() === 0)).toBe(true);
  });

  it('stays within the iOS 64 pending-notification limit', () => {
    expect(DAILY_REMINDER_DAYS_AHEAD).toBeLessThan(64);
  });
});
