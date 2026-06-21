import React, { useEffect, useState } from 'react';
import { View, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { LeaderboardList } from '../components/LeaderboardList';
import { leagueApi } from '../api/leagueApi';
import { colors } from '../theme/colors';
import { LeaderboardEntry } from '../types/guess';

type Props = NativeStackScreenProps<RootStackParamList, 'Leaderboard'>;

export function LeaderboardScreen({ route }: Props) {
  const { leagueId } = route.params;
  const [entries, setEntries] = useState<LeaderboardEntry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    leagueApi.leaderboard(leagueId)
      .then((res) => setEntries(res.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [leagueId]);

  if (loading) {
    return <View style={{ flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator color={colors.primary} /></View>;
  }

  return (
    <Screen padding={false}>
      <LeaderboardList entries={entries} />
    </Screen>
  );
}
