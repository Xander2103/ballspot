import React from 'react';
import { View, Text } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
type Props = NativeStackScreenProps<RootStackParamList, 'WeeklyLeaderboard'>;
export function WeeklyLeaderboardScreen({ route, navigation }: Props) {
  return <View><Text>Weekly Leaderboard (loading...)</Text></View>;
}
