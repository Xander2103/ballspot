/**
 * Store page links for the manual "Rate BallPicker" fallback (only when the
 * native in-app review is unavailable, and only after the user tapped the
 * button — never opened automatically). Pure TS, jest-tested.
 *
 * iOS: the App Store URL needs the app's numeric id, which only exists after
 * the first App Store Connect record. Set EXPO_PUBLIC_IOS_APP_STORE_URL in
 * mobile/eas.json (production + preview env) once known, preferably the
 * write-review deep link: https://apps.apple.com/app/id<ID>?action=write-review
 * Until then iOS has no store fallback (the button says it is unavailable).
 */

export const ANDROID_PACKAGE = 'be.vanmalderstudio.ballpicker';
export const PLAY_STORE_URL = `https://play.google.com/store/apps/details?id=${ANDROID_PACKAGE}`;

export interface StoreLinkEnv {
  EXPO_PUBLIC_IOS_APP_STORE_URL?: string;
}

export function resolveStoreUrl(platform: string, env: StoreLinkEnv): string | null {
  if (platform === 'android') return PLAY_STORE_URL;
  if (platform === 'ios') {
    const url = (env.EXPO_PUBLIC_IOS_APP_STORE_URL ?? '').trim();
    return url.startsWith('https://') || url.startsWith('itms-apps://') ? url : null;
  }
  return null; // web and anything else: no store page
}
