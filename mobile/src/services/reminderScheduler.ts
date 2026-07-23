import { notifications } from './notifications';
import { notificationsApi, type NotificationSettings } from '../api/notificationsApi';
import { dailyApi } from '../api/dailyApi';
import { leagueApi } from '../api/leagueApi';
import type { TodayResponse } from '../types/daily';
import type { League } from '../types/league';

/** Today's daily needs no action (already played, or none exists today). */
export function dailyIsHandled(today: TodayResponse | null): boolean {
  if (!today) return false;
  return !today.has_daily || today.already_played;
}

/**
 * The user has an outstanding tournament action. MVP heuristic: an active
 * tournament with rounds still to play. This is tournament-level, not a precise
 * per-user "you haven't guessed this round" signal — see docs for the limit.
 */
export function hasPendingTournament(leagues: League[]): boolean {
  return leagues.some((l) => l.status === 'active' && l.remaining_rounds_count > 0);
}

/**
 * Re-evaluate and (re)schedule local reminders from the given state. Safe to
 * call with data already loaded by a screen (no extra network).
 */
export async function applyReminderState(
  settings: NotificationSettings,
  today: TodayResponse | null,
  leagues: League[],
): Promise<void> {
  await notifications.syncSchedules({
    settings,
    dailyCompleted: dailyIsHandled(today),
    hasPendingTournament: hasPendingTournament(leagues),
  });
}

/**
 * Fetch the current settings + daily/tournament state from the server and
 * reschedule accordingly. Used when we don't already have that data in hand
 * (e.g. right after the user changes a setting in Profile). Best-effort.
 */
export async function refreshRemindersFromServer(): Promise<void> {
  if (!notifications.isSupported()) return;
  if ((await notifications.getPermissionStatus()) !== 'granted') return;
  try {
    const [settings, today, leagues] = await Promise.all([
      notificationsApi.getSettings(),
      dailyApi.today(),
      leagueApi.list(),
    ]);
    await applyReminderState(settings, today, leagues);
  } catch {
    // Offline / transient — reminders keep their previous schedule.
  }
}
