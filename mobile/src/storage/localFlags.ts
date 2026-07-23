import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

/**
 * Small persistent store for NON-sensitive UI flags that must survive app
 * restarts — e.g. "we already asked for notification permission" so we don't
 * nag on every launch. Never store secrets here (auth tokens use tokenStorage).
 * All operations are best-effort and never throw.
 */
export const localFlags = {
  async get(key: string): Promise<string | null> {
    try {
      if (Platform.OS === 'web') {
        return typeof localStorage !== 'undefined' ? localStorage.getItem(key) : null;
      }
      return await SecureStore.getItemAsync(key);
    } catch {
      return null;
    }
  },

  async set(key: string, value: string): Promise<void> {
    try {
      if (Platform.OS === 'web') {
        if (typeof localStorage !== 'undefined') localStorage.setItem(key, value);
      } else {
        await SecureStore.setItemAsync(key, value);
      }
    } catch {
      // best-effort — a failed flag write just means we might re-ask once.
    }
  },
};
