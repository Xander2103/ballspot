import { useEffect } from 'react';
import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import type { NavigationContainerRefWithCurrent } from '@react-navigation/native';
import { tokenStorage } from '../storage/tokenStorage';
import { canOpenFromNotification, routeForNotificationData } from '../utils/notificationRoutes';
import type { RootStackParamList } from './AppNavigator';

/** Responses already acted on (the cold-start response and the listener can both report the same tap). */
const handled = new Set<string>();

/**
 * Push tap → screen. Today only `data.type === 'club_competition'` routes
 * (→ ClubCompetition). Auth-gated like deep links: a tap only navigates with
 * a stored session while the navigator is inside the signed-in app; signed
 * out (or still on Login / verification / onboarding) it is ignored.
 * Covers both a tap that launched the app (last response) and taps while it runs.
 */
export function useNotificationRouting(
  navigationRef: NavigationContainerRefWithCurrent<RootStackParamList>,
  enabled: boolean,
): void {
  useEffect(() => {
    if (!enabled || Platform.OS === 'web') return;
    let active = true;

    const open = async (response: Notifications.NotificationResponse) => {
      if (response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) return;
      const id = response.notification.request.identifier;
      if (handled.has(id)) return;
      handled.add(id);
      const route = routeForNotificationData(response.notification.request.content.data);
      if (!route) return;
      const token = await tokenStorage.get().catch(() => null);
      if (!active || !navigationRef.isReady()) return;
      const names = navigationRef.getRootState()?.routes.map((r) => r.name) ?? [];
      if (!canOpenFromNotification(names, !!token)) return;
      navigationRef.navigate(route.name, route.params);
    };

    Notifications.getLastNotificationResponseAsync()
      .then((response) => {
        if (!response) return;
        void open(response);
        void Notifications.clearLastNotificationResponseAsync().catch(() => {});
      })
      .catch(() => {});
    const sub = Notifications.addNotificationResponseReceivedListener((response) => { void open(response); });
    return () => {
      active = false;
      sub.remove();
    };
  }, [enabled, navigationRef]);
}
