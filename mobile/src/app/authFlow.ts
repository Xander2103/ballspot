import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { isThemeName, ThemeName } from '../theme/themes';

type ApplyTheme = (name: ThemeName, opts?: { sync?: boolean }) => void;

/**
 * Fetch the profile, apply the saved theme, and decide the landing route.
 * Returns 'Home' or (for users who have not chosen a sport yet) 'SportSelection'.
 * Assumes a token is already stored.
 */
export async function applyProfileAndRoute(applyTheme: ApplyTheme): Promise<'Home' | 'SportSelection'> {
  try {
    const me = await authApi.me();
    if (me.selected_theme && isThemeName(me.selected_theme)) {
      applyTheme(me.selected_theme, { sync: false });
    }
    return me.preferred_sport ? 'Home' : 'SportSelection';
  } catch {
    return 'Home';
  }
}

/**
 * Finalize authentication once a token is obtained: persist it, then resolve
 * the landing route. Used after 2FA verification and the direct-token fallback.
 */
export async function completeLogin(token: string, applyTheme: ApplyTheme): Promise<'Home' | 'SportSelection'> {
  await tokenStorage.save(token);
  return applyProfileAndRoute(applyTheme);
}
