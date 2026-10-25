const fs = require('fs');
const path = require('path');

const baseConfig = require('./app.json');
const variant = process.env.APP_VARIANT || 'production';
const isStaging = variant === 'staging';

const bundleIdentifier = isStaging
  ? 'be.vanmalderstudio.ballpicker.staging'
  : 'be.vanmalderstudio.ballpicker';

const androidPackage = isStaging
  ? 'be.vanmalderstudio.ballpicker.staging'
  : 'be.vanmalderstudio.ballpicker';

const scheme = isStaging ? 'BallPickerSTG' : 'ballpicker';

const config = {
  ...baseConfig,
  expo: {
    ...baseConfig.expo,
    scheme,
    version: '1.0.1',
    ios: {
      ...baseConfig.expo.ios,
      bundleIdentifier,
      buildNumber: String(baseConfig.expo.ios.buildNumber || '33')
    },
    android: {
      ...baseConfig.expo.android,
      package: androidPackage,
      versionCode: Number(baseConfig.expo.android.versionCode || 1)
    }
  }
};

module.exports = config;
