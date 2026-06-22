import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'ballspot_token';

// expo-secure-store has no web implementation.
// On web, use sessionStorage (cleared on tab close) rather than localStorage
// (which persists and is readable by any JS on the page).
// Long-term: move to HttpOnly+Secure+SameSite cookie server-side + credentials:'include'.
export const tokenStorage = {
  async save(token: string): Promise<void> {
    if (Platform.OS === 'web') {
      sessionStorage.setItem(TOKEN_KEY, token);
    } else {
      await SecureStore.setItemAsync(TOKEN_KEY, token);
    }
  },
  async get(): Promise<string | null> {
    if (Platform.OS === 'web') {
      return sessionStorage.getItem(TOKEN_KEY);
    }
    return SecureStore.getItemAsync(TOKEN_KEY);
  },
  async remove(): Promise<void> {
    if (Platform.OS === 'web') {
      sessionStorage.removeItem(TOKEN_KEY);
    } else {
      await SecureStore.deleteItemAsync(TOKEN_KEY);
    }
  },
};
