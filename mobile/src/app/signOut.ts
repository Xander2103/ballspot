import AsyncStorage from '@react-native-async-storage/async-storage';
import { tokenStorage } from '../storage/tokenStorage';
import { localFlags } from '../storage/localFlags';
import { notifications } from '../services/notifications';

const THEME_STORAGE_KEY = 'ballspot_theme';
const NOTIF_PROMPT_SEEN = 'notif_prompt_seen';

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

  // Per-account UI state: notification prompt flag + persisted theme.
  try { await localFlags.remove(NOTIF_PROMPT_SEEN); } catch {}
  try { await AsyncStorage.removeItem(THEME_STORAGE_KEY); } catch {}

  // Finally, drop the credential.
  try { await tokenStorage.remove(); } catch {}
}
