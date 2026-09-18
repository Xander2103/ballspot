import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { isThemeName, ThemeName } from '../theme/themes';
import { setLocale } from '../i18n/core';
import { classifySessionError, routeForUser, LandingRoute } from '../utils/session';
import { signOut } from './signOut';

type ApplyTheme = (name: ThemeName, opts?: { sync?: boolean }) => void;

/**
 * Fetch the profile, apply the saved theme, and decide the landing route.
 * Returns 'Home', 'SportSelection' (no sport chosen yet) or 'EmailVerification'.
 * Assumes a token is already stored.
 *
 * A /me failure that means the token is unusable (401/403/404, or the
 * account_deleted / session_invalid codes) clears the stored token and
 * rethrows, so the calling screen shows its error instead of dropping the
 * user into a tab app that cannot load anything. A recoverable failure
 * (offline, 5xx) still lands on Home, whose header offers Retry + Logout.
 */
export async function applyProfileAndRoute(applyTheme: ApplyTheme): Promise<LandingRoute> {
  try {
    const me = await authApi.me();
    if (me.selected_theme && isThemeName(me.selected_theme)) {
      applyTheme(me.selected_theme, { sync: false });
    }
    // The account's language wins over the device/register choice (fallback
    // order rule 1). Unsupported values are ignored by setLocale.
    setLocale(me.preferred_language);
    return routeForUser(me);
  } catch (e: unknown) {
    if (classifySessionError(e, '/me') === 'invalid') {
      await signOut();
      throw e;
    }
    return 'Home';
  }
}

/**
 * Finalize authentication once a token is obtained: persist it, then resolve
 * the landing route. Used after 2FA verification and the direct-token fallback.
 */
export async function completeLogin(token: string, applyTheme: ApplyTheme): Promise<LandingRoute> {
  await tokenStorage.save(token);
  return applyProfileAndRoute(applyTheme);
}
