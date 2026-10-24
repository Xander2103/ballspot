import { useCallback } from 'react';
import { useFocusEffect } from '@react-navigation/native';
import { activateKeepAwakeAsync, deactivateKeepAwake } from 'expo-keep-awake';
import { startKeepAwakeSession } from '../utils/keepAwakeSession';

const api = { activate: activateKeepAwakeAsync, deactivate: deactivateKeepAwake };

/**
 * Keep the screen awake while this gameplay screen is focused (see
 * utils/keepAwakeSession). Blur, unmount and `navigation.replace` to the
 * result screen all release the lock, so the phone sleeps normally again
 * everywhere outside active play. `enabled: false` (e.g. still loading,
 * already played) holds no lock.
 */
export function useGameplayKeepAwake(tag: string, enabled = true): void {
  useFocusEffect(
    useCallback(() => {
      if (!enabled) return undefined;
      const session = startKeepAwakeSession(api, tag);
      return () => session.stop();
    }, [tag, enabled]),
  );
}
