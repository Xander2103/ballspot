import AsyncStorage from '@react-native-async-storage/async-storage';
import { tokenStorage } from '../storage/tokenStorage';
import { notifications } from '../services/notifications';

const THEME_STORAGE_KEY = 'ballspot_theme';

/**
 * Full LOCAL sign-out: clears everything the previous account left on this
 * device. Purely local (no API calls) so it works even when offline or after
 * the token is already revoked — callers do their server-side calls (logout /
 * push de-registration) BEFORE this, while the token still works.
 *
 * Every step is best-effort and never throws; the credential removal always
 * runs regardless of earlier failures.
 */
export async function signOut(): Promise<void> {
  // Scheduled local reminders belong to the signed-out account.
  try { await notifications.cancelAll(); } catch {}

  // The next account on this device registers its push token from scratch.
  // Invitation records stay: they are per account (see notificationInvites).
  try { await notifications.forgetDevice(); } catch {}

  // Per-account UI state: persisted theme.
  try { await AsyncStorage.removeItem(THEME_STORAGE_KEY); } catch {}

  // Finally, drop the credential.
  try { await tokenStorage.remove(); } catch {}
}
