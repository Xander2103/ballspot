import { ANDROID_PACKAGE, PLAY_STORE_URL, resolveStoreUrl } from '../storeLinks';

describe('store links', () => {
  it('always knows the Play Store page from the package id', () => {
    expect(ANDROID_PACKAGE).toBe('be.vanmalderstudio.ballpicker');
    expect(resolveStoreUrl('android', {})).toBe(PLAY_STORE_URL);
    expect(PLAY_STORE_URL).toBe('https://play.google.com/store/apps/details?id=be.vanmalderstudio.ballpicker');
  });

  it('uses the configured App Store URL on iOS and nothing otherwise', () => {
    expect(resolveStoreUrl('ios', {})).toBeNull();
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: ' https://apps.apple.com/app/id123?action=write-review ' }))
      .toBe('https://apps.apple.com/app/id123?action=write-review');
    expect(resolveStoreUrl('ios', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'javascript:alert(1)' })).toBeNull();
    expect(resolveStoreUrl('web', { EXPO_PUBLIC_IOS_APP_STORE_URL: 'https://apps.apple.com/app/id123' })).toBeNull();
  });
});
