import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { RecentXpCard } from '../components/RecentXpCard';
import { rankApi, RankThreshold } from '../api/rankApi';
import { authApi } from '../api/authApi';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { PlayerRank, XpEvent } from '../types/auth';

type Props = NativeStackScreenProps<RootStackParamList, 'RankOverview'>;

function fmt(n: number): string {
  return n.toLocaleString('en-US');
}

type Status = 'completed' | 'current' | 'future';

/**
 * Full rank ladder with the player's progression. Thresholds come from the
 * backend (GET /api/ranks) so the app never duplicates rank logic.
 */
export function RankOverviewScreen(_props: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [ranks, setRanks] = useState<RankThreshold[] | null>(null);
  const [myRank, setMyRank] = useState<PlayerRank | null>(null);
  const [xpEvents, setXpEvents] = useState<XpEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      rankApi.all(),
      authApi.stats().then((s) => s.rank).catch(() => null),
      // Recent XP is a nice-to-have here — never fail the ladder over it.
      authApi.xpEvents(5).then((r) => r.data.slice(0, 5)).catch(() => []),
    ])
      .then(([list, rank, xp]) => {
        if (cancelled) return;
        setRanks(list);
        setMyRank(rank);
        setXpEvents(xp);
      })
      .catch(() => !cancelled && setError(true))
      .finally(() => !cancelled && setLoading(false));
    return () => { cancelled = true; };
  }, []);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  if (error || !ranks) {
    return (
      <Screen padding>
        <Text style={styles.errorText}>Could not load ranks. Please try again.</Text>
      </Screen>
    );
  }

  const currentLevel = myRank?.level ?? 0;
  const totalXp = myRank?.total_xp ?? 0;
  const maxLevel = ranks.reduce((m, r) => Math.max(m, r.level), 0);

  return (
    <Screen scroll padding>
      <Text style={styles.heading}>Rank ladder</Text>
      <Text style={styles.subheading}>
        {myRank
          ? `You have ${fmt(totalXp)} XP — every rank and the XP needed to reach it.`
          : 'Every rank and the XP needed to reach it.'}
      </Text>

      <View style={styles.list}>
        {ranks.map((rank) => {
          const status: Status =
            rank.level < currentLevel ? 'completed'
              : rank.level === currentLevel ? 'current'
                : 'future';
          const isMax = rank.level === maxLevel;
          const xpNeeded = Math.max(0, rank.min_xp - totalXp);

          return (
            <View
              key={rank.level}
              style={[styles.row, status === 'current' && styles.rowCurrent]}
            >
              <View style={[styles.levelBadge, status === 'current' && styles.levelBadgeCurrent]}>
                {status === 'completed'
                  ? <Text style={styles.check}>✓</Text>
                  : <Text style={styles.levelText}>{rank.level}</Text>}
              </View>

              <View style={styles.rowMain}>
                <Text style={styles.rankName} numberOfLines={1}>
                  {rank.name}
                  <Text style={styles.levelLabel}>{`  ·  Level ${rank.level}`}</Text>
                </Text>
                <Text style={styles.minXp}>{fmt(rank.min_xp)} XP</Text>
              </View>

              <View style={styles.statusWrap}>
                {isMax ? <Text style={styles.maxTag}>Max rank</Text> : null}
                {status === 'current' ? (
                  <Text style={[styles.statusPill, styles.statusCurrent]}>Current rank</Text>
                ) : status === 'completed' ? (
                  <Text style={[styles.statusPill, styles.statusDone]}>Completed</Text>
                ) : (
                  <Text style={[styles.statusPill, styles.statusFuture]}>
                    {xpNeeded > 0 ? `${fmt(xpNeeded)} XP needed` : 'Reached'}
                  </Text>
                )}
              </View>
            </View>
          );
        })}
      </View>

      {/* The XP that feeds the ladder — compact, right where progression lives. */}
      {xpEvents.length > 0 ? (
        <>
          <Text style={styles.xpLabel}>Recent XP</Text>
          <RecentXpCard events={xpEvents} />
        </>
      ) : null}
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    errorText: { fontSize: 14, color: theme.textMuted, textAlign: 'center', marginTop: spacing.xl },
    heading: { fontSize: 22, fontWeight: '800', color: theme.text, marginBottom: 4 },
    subheading: { fontSize: 13, color: theme.textSecondary, marginBottom: spacing.xl },
    list: { gap: spacing.sm },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      backgroundColor: theme.surface,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.md,
    },
    rowCurrent: { borderColor: theme.primary, borderWidth: 2, backgroundColor: theme.surfaceElevated },
    levelBadge: {
      width: 40, height: 40, borderRadius: 10,
      backgroundColor: theme.surfaceElevated,
      borderWidth: 1, borderColor: theme.border,
      alignItems: 'center', justifyContent: 'center', marginRight: spacing.md,
    },
    levelBadgeCurrent: { backgroundColor: theme.gold, borderColor: theme.gold },
    levelText: { fontSize: 18, fontWeight: '900', color: theme.text },
    check: { fontSize: 18, fontWeight: '900', color: theme.primary },
    rowMain: { flex: 1, marginRight: spacing.sm },
    rankName: { fontSize: 16, fontWeight: '800', color: theme.text },
    levelLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary },
    minXp: { fontSize: 13, fontWeight: '700', color: theme.score, marginTop: 2 },
    statusWrap: { alignItems: 'flex-end', gap: 4 },
    maxTag: { fontSize: 9, fontWeight: '800', color: theme.gold, letterSpacing: 0.5, textTransform: 'uppercase' },
    statusPill: { fontSize: 11, fontWeight: '700', overflow: 'hidden' },
    statusCurrent: { color: theme.primary },
    statusDone: { color: theme.textMuted },
    statusFuture: { color: theme.textSecondary },
    xpLabel: {
      fontSize: 11, fontWeight: '700', color: theme.textMuted, letterSpacing: 1,
      textTransform: 'uppercase', marginTop: spacing.lg, marginBottom: spacing.xs,
    },
  });
}
