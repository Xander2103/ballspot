import React, { useEffect, useState } from 'react';
import { View, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { LeaderboardList } from '../components/LeaderboardList';
import { YourPositionCard } from '../components/YourPositionCard';
import { leagueApi } from '../api/leagueApi';
import { colors } from '../theme/colors';
import { LeaderboardEntry } from '../types/guess';
import type { LeaderboardMeta } from '../types/daily';

type Props = NativeStackScreenProps<RootStackParamList, 'Leaderboard'>;

export function LeaderboardScreen({ route }: Props) {
  const { leagueId } = route.params;
  const [entries, setEntries] = useState<LeaderboardEntry[]>([]);
  const [meta, setMeta] = useState<LeaderboardMeta<LeaderboardEntry> | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!leagueId) { setLoading(false); return; }
    leagueApi.leaderboard(leagueId)
      .then((res) => {
        setEntries(res.data ?? []);
        setMeta(res.meta ?? null);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [leagueId]);

  if (loading) {
    return <View style={{ flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator color={colors.primary} /></View>;
  }

  return (
    <Screen padding={false}>
      {meta?.current_user_rank ? (
        <YourPositionCard
          rank={meta.current_user_rank}
          totalPlayers={meta.total_players}
          betterThanPercentage={meta.better_than_percentage}
          score={meta.current_user_score}
        />
      ) : null}
      {/* flex: 1 bounds the list so it scrolls internally on small screens. */}
      <LeaderboardList entries={entries} style={{ flex: 1 }} />
    </Screen>
  );
}
