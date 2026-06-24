import React from 'react';
import { View, Text } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
type Props = NativeStackScreenProps<RootStackParamList, 'DailyResult'>;
export function DailyResultScreen({ route, navigation }: Props) {
  return <View><Text>Daily Result (loading...)</Text></View>;
}
