import {
  ANDROID_PACKAGE, APP_STORE_REVIEW_URL, IOS_APP_STORE_ID, PLAY_STORE_REVIEW_URL, PLAY_STORE_URL, resolveStoreUrl,
} from '../storeLinks';

describe('store links', () => {
  it('opens the Play Store review section on Android', () => {
    expect(ANDROID_PACKAGE).toBe('be.vanmalderstudio.ballpicker');
    expect(PLAY_STORE_URL).toBe('https://play.google.com/store/apps/details?id=be.vanmalderstudio.ballpicker');
    expect(PLAY_STORE_REVIEW_URL)
      .toBe('https://play.google.com/store/apps/details?id=be.vanmalderstudio.ballpicker&showAllReviews=true');
    expect(resolveStoreUrl('android', {})).toBe(PLAY_STORE_REVIEW_URL);
    expect(resolveStoreUrl('android')).toBe(PLAY_STORE_REVIEW_URL);
  });

  it('opens the App Store write-review page on iOS without any build env', () => {
    expect(IOS_APP_STORE_ID).toBe('6796491992');
    expect(APP_STORE_REVIEW_URL).toBe('https://apps.apple.com/app/id6796491992?action=write-review');
    expect(resolveStoreUrl('ios', {})).toBe(APP_STORE_REVIEW_URL);
    expect(resolveStoreUrl('ios')).toBe(APP_STORE_REVIEW_URL);
  });

  it('accepts only App Store overrides on iOS', () => {
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: ' https://apps.apple.com/app/id123?action=write-review ' }))
      .toBe('https://apps.apple.com/app/id123?action=write-review');
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'javascript:alert(1)' })).toBe(APP_STORE_REVIEW_URL);
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'https://evil.example/app' })).toBe(APP_STORE_REVIEW_URL);
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'https://apps.apple.com.evil.example/x' })).toBe(APP_STORE_REVIEW_URL);
  });

  it('has no store page on web', () => {
    expect(resolveStoreUrl('web', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'https://apps.apple.com/app/id123' })).toBeNull();
  });
});
