import { useCallback } from 'react';
import { BackHandler, Platform } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import type { NavigationProp } from '@react-navigation/native';
import type { RootStackParamList } from './AppNavigator';

export type GameNavigation = Pick<NavigationProp<RootStackParamList>, 'reset'>;

/**
 * Leave a game flow for the Home screen. A full reset (rather than navigate)
 * guarantees no game screen is left underneath, so Home's back is a true exit.
 */
export function goHome(navigation: GameNavigation): void {
  navigation.reset({ index: 0, routes: [{ name: 'Home' }] });
}

/** Leave a pack flow for the Packs list, with Home underneath it. */
export function goPacks(navigation: GameNavigation): void {
  navigation.reset({ index: 1, routes: [{ name: 'Home' }, { name: 'Packs' }] });
}

/**
 * Route the Android hardware back button to `handler` while the screen is
 * focused. No-op on iOS/web, where there is no hardware back button.
 */
export function useHardwareBack(handler: () => void): void {
  useFocusEffect(
    useCallback(() => {
      if (Platform.OS !== 'android') return;
      const sub = BackHandler.addEventListener('hardwareBackPress', () => {
        handler();
        return true; // handled — do not pop the stack
      });
      return () => sub.remove();
    }, [handler])
  );
}
