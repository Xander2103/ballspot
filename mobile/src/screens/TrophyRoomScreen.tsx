import React from 'react';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { TrophyRoom } from '../components/TrophyRoom';

type Props = NativeStackScreenProps<RootStackParamList, 'TrophyRoom'>;

/**
 * Dedicated Trophy Room screen. Reuses the existing self-fetching TrophyRoom
 * component (earned + locked badges) — no rebuild — behind its own route so it
 * is easy to discover from the Profile "Trophy Room" card.
 */
export function TrophyRoomScreen(_props: Props) {
  return (
    <Screen scroll padding>
      <TrophyRoom />
    </Screen>
  );
}
