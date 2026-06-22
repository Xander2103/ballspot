import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, Alert } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { LeaderboardList } from '../components/LeaderboardList';
import { leagueApi } from '../api/leagueApi';
import { roundApi } from '../api/roundApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { LeaderboardEntry } from '../types/guess';

type Props = NativeStackScreenProps<RootStackParamList, 'LeagueDetail'>;

export function LeagueDetailScreen({ route, navigation }: Props) {
  const { leagueId, leagueName } = route.params;
  const [league, setLeague] = useState<League | null>(null);
  const [leaderboard, setLeaderboard] = useState<LeaderboardEntry[]>([]);
  const [hasRound, setHasRound] = useState(false);
  const [roundId, setRoundId] = useState<number | null>(null);
  const [progress, setProgress] = useState<{ completed: number; total: number; pct: number } | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const [l, lb, cr] = await Promise.all([
        leagueApi.get(leagueId),
        leagueApi.leaderboard(leagueId),
        roundApi.currentRound(leagueId),
      ]);
      setLeague(l);
      setLeaderboard(lb.data ?? []);
      setHasRound(!cr.completed && cr.current_round !== null);
      if (cr.current_round) setRoundId(cr.current_round.id);
      if (cr.progress) setProgress(cr.progress);
    } catch {
      Alert.alert('Error', 'Failed to load league');
    } finally {
      setLoading(false);
    }
  }, [leagueId]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const u = navigation.addListener('focus', load); return u; }, [navigation, load]);

  return (
    <Screen scroll padding={false}>
      <View style={styles.header}>
        <Text style={styles.code}>Code: {league?.join_code || '…'}</Text>
        <Text style={styles.meta}>{league?.members_count ?? 0} players · {league?.total_rounds ?? 0} rounds</Text>
      </View>

      <View style={styles.actions}>
        {hasRound && roundId ? (
          <AppButton
            title="▶ Play Current Round"
            onPress={() => navigation.navigate('Guess', { leagueId, roundId, leagueName })}
            style={styles.playBtn}
          />
        ) : (
          <View style={styles.doneBox}>
            <Text style={styles.doneText}>✓ All rounds completed for now</Text>
          </View>
        )}
        {progress && (
          <View style={styles.progressBox}>
            <View style={styles.progressBarBg}>
              <View style={[styles.progressBarFill, { width: `${progress.pct}%` }]} />
            </View>
            <Text style={styles.progressText}>
              {progress.completed}/{progress.total} rounds completed ({progress.pct}%)
            </Text>
          </View>
        )}
        <AppButton
          title="Full Leaderboard"
          onPress={() => navigation.navigate('Leaderboard', { leagueId, leagueName })}
          variant="secondary"
        />
      </View>

      <Text style={styles.sectionTitle}>Leaderboard Preview</Text>
      <LeaderboardList entries={leaderboard.slice(0, 3)} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { backgroundColor: colors.surface, padding: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.border },
  code: { fontSize: 20, fontWeight: '700', color: colors.primary, letterSpacing: 4, marginBottom: 4 },
  meta: { fontSize: 13, color: colors.textSecondary },
  actions: { padding: spacing.md, gap: spacing.sm },
  playBtn: { marginBottom: 0 },
  doneBox: { backgroundColor: colors.surface, borderRadius: 12, padding: spacing.md, alignItems: 'center' },
  doneText: { color: colors.success, fontWeight: '600' },
  progressBox: { marginTop: 4 },
  progressBarBg: { height: 6, backgroundColor: colors.border, borderRadius: 3, overflow: 'hidden' },
  progressBarFill: { height: 6, backgroundColor: colors.primary, borderRadius: 3 },
  progressText: { fontSize: 12, color: colors.textSecondary, marginTop: 4, textAlign: 'center' },
  sectionTitle: { fontSize: 16, fontWeight: '700', color: colors.text, paddingHorizontal: spacing.md, paddingTop: spacing.md, paddingBottom: spacing.sm },
});
