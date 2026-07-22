import React, { createContext, useCallback, useEffect, useMemo, useState } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { DEFAULT_THEME, isThemeName, themes, ThemeName, ThemeTokens } from './themes';
import { preferencesApi } from '../api/preferencesApi';

const STORAGE_KEY = 'ballspot_theme';

interface ThemeContextValue {
  theme: ThemeTokens;
  themeName: ThemeName;
  /** Change the theme. By default also syncs to the backend (best effort). */
  setTheme: (name: ThemeName, options?: { sync?: boolean }) => void;
  ready: boolean;
}

export const ThemeContext = createContext<ThemeContextValue>({
  theme: themes[DEFAULT_THEME],
  themeName: DEFAULT_THEME,
  setTheme: () => {},
  ready: false,
});

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [themeName, setThemeName] = useState<ThemeName>(DEFAULT_THEME);
  const [ready, setReady] = useState(false);

  // Load the locally-persisted theme on boot so the choice survives restarts
  // even before the server profile is fetched.
  useEffect(() => {
    let active = true;
    AsyncStorage.getItem(STORAGE_KEY)
      .then((stored) => {
        if (active && isThemeName(stored)) {
          setThemeName(stored);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (active) setReady(true);
      });
    return () => {
      active = false;
    };
  }, []);

  const setTheme = useCallback((name: ThemeName, options?: { sync?: boolean }) => {
    if (!isThemeName(name)) return;
    setThemeName(name);
    AsyncStorage.setItem(STORAGE_KEY, name).catch(() => {});

    // Persist to backend unless this change originated FROM the server
    // (e.g. applied right after login). Failures are non-fatal — the local
    // value already applied and will sync on a later successful call.
    if (options?.sync !== false) {
      preferencesApi.update({ selected_theme: name }).catch(() => {});
    }
  }, []);

  const value = useMemo<ThemeContextValue>(
    () => ({ theme: themes[themeName], themeName, setTheme, ready }),
    [themeName, setTheme, ready]
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
