/**
 * Store review destinations for the manual "Rate BallPicker" button (only
 * after the user tapped it — never opened automatically). Pure TS,
 * jest-tested.
 *
 * Both URLs are derived from permanent store identifiers (the App Store
 * numeric id and the Android package never change for this app), so they are
 * bundled rather than fetched: the button works offline and no remote value
 * can send players to another domain. The pages only resolve once the app is
 * live in the respective store.
 *
 * EXPO_PUBLIC_IOS_APP_STORE_URL may still override the iOS destination at
 * build time, but only with an apps.apple.com / itms-apps link.
 */

export const IOS_APP_STORE_ID = '6796491992';
export const ANDROID_PACKAGE = 'be.vanmalderstudio.ballpicker';

export const APP_STORE_REVIEW_URL = `https://apps.apple.com/app/id${IOS_APP_STORE_ID}?action=write-review`;
export const PLAY_STORE_URL = `https://play.google.com/store/apps/details?id=${ANDROID_PACKAGE}`;
export const PLAY_STORE_REVIEW_URL = `${PLAY_STORE_URL}&showAllReviews=true`;

export interface StoreLinkEnv {
  EXPO_PUBLIC_IOS_APP_STORE_URL?: string;
}

function isAppStoreUrl(url: string): boolean {
  return url.startsWith('https://apps.apple.com/') || url.startsWith('itms-apps://');
}

export function resolveStoreUrl(platform: string, env: StoreLinkEnv = {}): string | null {
  if (platform === 'android') return PLAY_STORE_REVIEW_URL;
  if (platform === 'ios') {
    const override = (env.EXPO_PUBLIC_IOS_APP_STORE_URL ?? '').trim();
    return override && isAppStoreUrl(override) ? override : APP_STORE_REVIEW_URL;
  }
  return null; // web and anything else: no store page
}
