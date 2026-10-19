import { useCallback } from 'react';
import { BackHandler, Platform } from 'react-native';
import { CommonActions, StackActions, useFocusEffect } from '@react-navigation/native';
import type { NavigationProp } from '@react-navigation/native';
import type { RootStackParamList } from './AppNavigator';
import { detailRouteFor, exitRoutesFor, isClubSession, type PlaySession } from '../utils/playSession';

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
 * Leave a play flow for where it started ("return to origin"): a public pack
 * goes to the Packs list exactly like goPacks; a club pack returns to its
 * club's detail (Clubs tab underneath). See utils/playSession.ts.
 */
export function exitPlaySession(navigation: GameNavigation, session: PlaySession | null | undefined): void {
  if (!isClubSession(session)) {
    goPacks(navigation);
    return;
  }
  navigation.reset(exitRoutesFor(session));
}

/**
 * Open the session's "detail" screen — PackDetail for packs, the competition
 * screen for a competition attempt — replacing the current game screen
 * (bounce-back / error Back) or on top of it ("Leave pack").
 */
export function goToPlayDetail(
  navigation: Pick<NavigationProp<RootStackParamList>, 'dispatch'>,
  session: PlaySession | null | undefined,
  slug: string,
  packName: string,
  mode: 'replace' | 'navigate',
): void {
  const route = detailRouteFor(session, slug, packName);
  if (mode === 'replace') {
    navigation.dispatch(StackActions.replace(route.name, route.params));
  } else if (route.name === 'ClubCompetition') {
    // The competition screen is already underneath: go back to it, don't stack a second one.
    navigation.dispatch(StackActions.popTo(route.name, route.params));
  } else {
    navigation.dispatch(CommonActions.navigate(route.name, route.params));
  }
}

/** Leave a club flow (left the club / club gone) for the Clubs tab. */
export function goClubs(navigation: GameNavigation): void {
  navigation.reset({ index: 0, routes: [{ name: 'Home', params: { screen: 'Clubs' } }] });
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
