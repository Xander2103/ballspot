import React from 'react';
import { View, Text } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
type Props = NativeStackScreenProps<RootStackParamList, 'DailyChallenge'>;
export function DailyChallengeScreen({ route, navigation }: Props) {
  return <View><Text>Daily Challenge (loading...)</Text></View>;
}
