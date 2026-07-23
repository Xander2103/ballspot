import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, FlatList, ActivityIndicator, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { YourPositionCard } from '../components/YourPositionCard';
import { dailyApi } from '../api/dailyApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { WeeklyLeaderboardEntry, WeeklyLeaderboard } from '../types/daily';

type Props = NativeStackScreenProps<RootStackParamList, 'WeeklyLeaderboard'>;

function RankMedal({ rank }: { rank: number }): string {
  if (rank === 1) return '🥇';
  if (rank === 2) return '🥈';
  if (rank === 3) return '🥉';
  return `#${rank}`;
}

function LeaderboardRow({ entry }: { entry: WeeklyLeaderboardEntry }) {
  const isCurrentUser = entry.is_current_user;
  const rankDisplay = RankMedal({ rank: entry.rank });
  const displayName = entry.name || entry.username;

  return (
    <View style={[styles.row, isCurrentUser && styles.rowHighlighted]}>
      <View style={styles.rankCell}>
        <Text style={[styles.rankText, entry.rank <= 3 && styles.rankMedal]}>
          {rankDisplay}
        </Text>
      </View>
      <View style={styles.nameCell}>
        <Text style={[styles.nameText, isCurrentUser && styles.nameTextHighlighted]} numberOfLines={1}>
          {displayName}
        </Text>
        {isCurrentUser && (
          <Text style={styles.youLabel}>You</Text>
        )}
      </View>
      <View style={styles.scoreCell}>
        <Text style={[styles.totalScore, isCurrentUser && styles.totalScoreHighlighted]}>
          {entry.total_score}
        </Text>
        <Text style={styles.subScore}>
          {entry.challenges_played}x · avg {Math.round(entry.avg_score)}
        </Text>
      </View>
    </View>
  );
}

export function WeeklyLeaderboardScreen({ navigation }: Props) {
  const [leaderboard, setLeaderboard] = useState<WeeklyLeaderboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [view, setView] = useState<'top' | 'me'>('top');

  useEffect(() => {
    let cancelled = false;

    dailyApi.weeklyLeaderboard()
      .then((data) => {
        if (!cancelled) {
          setLeaderboard(data);
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError(true);
          setLoading(false);
        }
      });

    return () => { cancelled = true; };
  }, []);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  if (error || !leaderboard) {
    return (
      <Screen padding>
        <Text style={styles.errorText}>Failed to load leaderboard.</Text>
        <AppButton
          title="Back Home"
          onPress={() => navigation.navigate('Home')}
          style={{ marginTop: spacing.lg }}
        />
      </Screen>
    );
  }

  const meta = leaderboard.meta;
  const hasRank = !!meta?.current_user_rank;
  const entries = view === 'me' && hasRank ? meta.nearby_users : leaderboard.data;

  return (
    <Screen scroll={false} padding={false}>
      {/* Week header */}
      <View style={styles.weekHeader}>
        <Text style={styles.weekLabel}>
          Week of {leaderboard.week_start} – {leaderboard.week_end}
        </Text>
      </View>

      {/* Your position summary */}
      {hasRank ? (
        <YourPositionCard
          rank={meta.current_user_rank}
          totalPlayers={meta.total_players}
          betterThanPercentage={meta.better_than_percentage}
          score={meta.current_user_score}
        />
      ) : null}

      {/* Top / My rank toggle */}
      {hasRank ? (
        <View style={styles.toggleRow}>
          <Pressable
            onPress={() => setView('top')}
            style={[styles.toggleBtn, view === 'top' && styles.toggleBtnActive]}
          >
            <Text style={[styles.toggleText, view === 'top' && styles.toggleTextActive]}>🏆 Top</Text>
          </Pressable>
          <Pressable
            onPress={() => setView('me')}
            style={[styles.toggleBtn, view === 'me' && styles.toggleBtnActive]}
          >
            <Text style={[styles.toggleText, view === 'me' && styles.toggleTextActive]}>📍 My rank</Text>
          </Pressable>
        </View>
      ) : null}

      {/* Column headers */}
      <View style={styles.columnHeader}>
        <View style={styles.rankCell}>
          <Text style={styles.colHeaderText}>Rank</Text>
        </View>
        <View style={styles.nameCell}>
          <Text style={styles.colHeaderText}>Player</Text>
        </View>
        <View style={styles.scoreCell}>
          <Text style={[styles.colHeaderText, styles.colHeaderRight]}>Score</Text>
        </View>
      </View>

      {entries.length === 0 ? (
        <View style={styles.emptyState}>
          <Text style={styles.emptyText}>No scores yet this week.</Text>
          <Text style={styles.emptySubText}>Play today's challenge to get on the board!</Text>
        </View>
      ) : (
        <FlatList<WeeklyLeaderboardEntry>
          data={entries}
          keyExtractor={(item) => String(item.user_id)}
          renderItem={({ item }) => <LeaderboardRow entry={item} />}
          style={styles.list}
          contentContainerStyle={styles.listContent}
          showsVerticalScrollIndicator={false}
        />
      )}

      <View style={styles.footer}>
        <AppButton
          title="Back Home"
          onPress={() => navigation.navigate('Home')}
          variant="secondary"
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  weekHeader: {
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    alignItems: 'center',
  },
  weekLabel: {
    fontSize: 13,
    color: colors.textSecondary,
    fontWeight: '600',
    letterSpacing: 0.5,
  },
  toggleRow: {
    flexDirection: 'row',
    marginHorizontal: spacing.md,
    marginBottom: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: 10,
    padding: 3,
    gap: 3,
  },
  toggleBtn: {
    flex: 1,
    paddingVertical: spacing.xs,
    borderRadius: 8,
    alignItems: 'center',
  },
  toggleBtnActive: {
    backgroundColor: colors.surfaceElevated,
  },
  toggleText: {
    fontSize: 13,
    fontWeight: '700',
    color: colors.textMuted,
  },
  toggleTextActive: {
    color: colors.text,
  },
  columnHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    backgroundColor: colors.surface,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  colHeaderText: {
    fontSize: 11,
    fontWeight: '700',
    color: colors.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  colHeaderRight: {
    textAlign: 'right',
  },
  list: {
    // Bound the list so it scrolls internally instead of pushing the footer /
    // "Back Home" button off-screen when a week has many players.
    flex: 1,
  },
  listContent: {
    paddingBottom: spacing.md,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    backgroundColor: colors.background,
  },
  rowHighlighted: {
    backgroundColor: colors.primary + '20',
  },
  rankCell: {
    width: 48,
    alignItems: 'center',
  },
  rankText: {
    fontSize: 14,
    fontWeight: '700',
    color: colors.textSecondary,
  },
  rankMedal: {
    fontSize: 20,
  },
  nameCell: {
    flex: 1,
    paddingHorizontal: spacing.sm,
  },
  nameText: {
    fontSize: 15,
    fontWeight: '600',
    color: colors.text,
  },
  nameTextHighlighted: {
    color: colors.primary,
  },
  youLabel: {
    fontSize: 10,
    fontWeight: '700',
    color: colors.primary,
    letterSpacing: 0.5,
    textTransform: 'uppercase',
    marginTop: 1,
  },
  scoreCell: {
    width: 80,
    alignItems: 'flex-end',
  },
  totalScore: {
    fontSize: 17,
    fontWeight: '800',
    color: colors.text,
  },
  totalScoreHighlighted: {
    color: colors.primary,
  },
  subScore: {
    fontSize: 11,
    color: colors.textMuted,
    marginTop: 1,
  },
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.xxl,
    paddingHorizontal: spacing.lg,
  },
  emptyText: {
    fontSize: 16,
    fontWeight: '600',
    color: colors.textSecondary,
    textAlign: 'center',
    marginBottom: spacing.xs,
  },
  emptySubText: {
    fontSize: 13,
    color: colors.textMuted,
    textAlign: 'center',
    fontStyle: 'italic',
  },
  errorText: {
    color: colors.textSecondary,
    fontSize: 15,
    textAlign: 'center',
  },
  footer: {
    padding: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.background,
  },
});
