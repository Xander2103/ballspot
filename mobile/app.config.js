/**
 * Dynamic app config on top of app.json.
 *
 * Production (APP_VARIANT unset) returns app.json UNCHANGED.
 *
 * APP_VARIANT=staging builds a separate STAGING app identity that installs next
 * to the store app and can never be mistaken for it:
 *   - bundle id / package  be.vanmalderstudio.ballpicker.staging
 *   - name "BallPicker STG", scheme "ballpicker-staging"
 *   - extra.appVariant = 'staging' → the in-app STAGING indicator and the
 *     no-production-fallback URL guard (src/utils/urls.ts).
 * Store-related fields that only make sense for production are dropped.
 */
const STAGING_ID = 'be.vanmalderstudio.ballpicker.staging';
// Apple team for local signing of the staging app (not a secret; production
// signing stays with EAS-managed credentials and is untouched).
const APPLE_TEAM_ID = 'JF3PTL4U56';

module.exports = ({ config }) => {
  if (process.env.APP_VARIANT !== 'staging') {
    return config;
  }

  const android = { ...config.android, package: STAGING_ID };
  delete android.playStoreUrl;

  return {
    ...config,
    name: 'BallPicker STG',
    scheme: 'ballpicker-staging',
    ios: { ...config.ios, bundleIdentifier: STAGING_ID, appleTeamId: APPLE_TEAM_ID },
    android,
    extra: { ...config.extra, appVariant: 'staging' },
  };
};
