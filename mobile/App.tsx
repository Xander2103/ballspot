import React, { useCallback, useEffect, useRef, useState } from 'react';
import { enableScreens } from 'react-native-screens';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import * as SplashScreen from 'expo-splash-screen';
import { AppNavigator } from './src/app/AppNavigator';
import { ThemeProvider } from './src/theme/ThemeProvider';

enableScreens();

/** Minimum time the native splash stays visible (ms). */
const SPLASH_MIN_MS = 800;
/** Hard cap: the splash always hides after this, whatever the app state (ms). */
const SPLASH_MAX_MS = 6000;

// Keep the native splash up until we explicitly hide it. Must run before the
// first render. Rejections (e.g. web, or already hidden) are harmless.
SplashScreen.preventAutoHideAsync().catch(() => {});

const hideSplash = () => {
  SplashScreen.hideAsync().catch(() => {});
};

export default function App() {
  const [minTimePassed, setMinTimePassed] = useState(false);
  const [appReady, setAppReady] = useState(false);
  const hidden = useRef(false);

  useEffect(() => {
    const minTimer = setTimeout(() => setMinTimePassed(true), SPLASH_MIN_MS);
    // Safety net: never get stuck on the splash if init hangs or throws.
    const maxTimer = setTimeout(() => {
      if (!hidden.current) {
        hidden.current = true;
        hideSplash();
      }
    }, SPLASH_MAX_MS);
    return () => {
      clearTimeout(minTimer);
      clearTimeout(maxTimer);
    };
  }, []);

  useEffect(() => {
    if (minTimePassed && appReady && !hidden.current) {
      hidden.current = true;
      hideSplash();
    }
  }, [minTimePassed, appReady]);

  // Called by AppNavigator once the initial route (auth check) is resolved
  // and rendered — success or failure.
  const handleReady = useCallback(() => setAppReady(true), []);

  return (
    <SafeAreaProvider>
      <ThemeProvider>
        <AppNavigator onReady={handleReady} />
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
