import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { isThemeName, ThemeName } from '../theme/themes';

/**
 * Finalize a successful authentication: persist the token, apply the user's
 * saved theme, and decide the landing route. Returns 'Home' or (for users who
 * have not chosen a sport yet) 'SportSelection'.
 *
 * Used both after 2FA verification and for the direct-token fallback.
 */
export async function completeLogin(
  token: string,
  applyTheme: (name: ThemeName, opts?: { sync?: boolean }) => void,
): Promise<'Home' | 'SportSelection'> {
  await tokenStorage.save(token);
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
