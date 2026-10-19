import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { APP_VARIANT, API_BASE_URL } from '../utils/urls';
import { STAGING_UNCONFIGURED_API_BASE_URL, hostOf } from '../utils/appVariant';

/**
 * Small, always-on-top "STAGING" label — rendered ONLY in the staging app
 * variant (separate bundle id, see app.config.js). Shows the backend host the
 * build talks to, or a warning when no staging URL was configured (the build
 * then refuses to use production, see utils/urls). Never intercepts touches.
 * Technical label, intentionally not translated.
 */
export function StagingRibbon() {
  const insets = useSafeAreaInsets();
  if (APP_VARIANT !== 'staging') return null;

  const unconfigured = API_BASE_URL === STAGING_UNCONFIGURED_API_BASE_URL;
  const label = unconfigured ? 'STAGING · NO STAGING API CONFIGURED' : `STAGING · ${hostOf(API_BASE_URL) ?? '?'}`;

  return (
    <View pointerEvents="none" style={[styles.wrap, { top: insets.top + 2 }]} accessibilityLabel={label}>
      <Text style={[styles.text, unconfigured && styles.warn]} numberOfLines={1}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    position: 'absolute',
    alignSelf: 'center',
    zIndex: 1000,
    elevation: 1000,
  },
  text: {
    backgroundColor: '#FFB020',
    color: '#111111',
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.5,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 8,
    overflow: 'hidden',
    maxWidth: 320,
  },
  warn: {
    backgroundColor: '#E5484D',
    color: '#FFFFFF',
  },
});
