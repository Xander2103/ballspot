import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { PlayerRank } from '../types/auth';

function fmt(n: number): string {
  return n.toLocaleString('en-US');
}

/**
 * Personal progression card: rank name, level badge, lifetime XP and a progress
 * bar toward the next rank. Sporty/premium, theme-aware. This is NOT leaderboard
 * position — it is long-term personal progression.
 */
export function RankCard({ rank }: { rank: PlayerRank }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const pct = rank.is_max_rank ? 100 : Math.max(0, Math.min(100, rank.progress_to_next_rank_pct));

  return (
    <View style={styles.card}>
      <View style={styles.headerRow}>
        <View style={styles.levelBadge}>
          <Text style={styles.levelText}>{rank.level}</Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.rankName}>
            {rank.name}
            <Text style={styles.level}>{rank.is_max_rank ? '  ·  Max level' : `  ·  Level ${rank.level}`}</Text>
          </Text>
          <Text style={styles.xp}>{fmt(rank.total_xp)} XP</Text>
        </View>
      </View>

      <View style={styles.track}>
        <View style={[styles.fill, { width: `${pct}%` }]} />
      </View>

      <Text style={styles.footer}>
        {rank.is_max_rank
          ? 'No next rank — you reached the top.'
          : `${fmt(rank.xp_to_next_rank ?? 0)} XP to ${rank.next_rank_name} · ${pct}%`}
      </Text>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surfaceElevated,
      borderRadius: 16,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.md,
      marginBottom: spacing.xl,
    },
    headerRow: { flexDirection: 'row', alignItems: 'center', marginBottom: spacing.md },
    levelBadge: {
      width: 46,
      height: 46,
      borderRadius: 12,
      backgroundColor: theme.gold,
      alignItems: 'center',
      justifyContent: 'center',
      marginRight: spacing.md,
    },
    levelText: { fontSize: 22, fontWeight: '900', color: '#1a1400' },
    rankName: { fontSize: 18, fontWeight: '800', color: theme.text },
    level: { fontSize: 13, fontWeight: '600', color: theme.textSecondary },
    xp: { fontSize: 13, fontWeight: '700', color: theme.score, marginTop: 2 },
    track: {
      height: 10,
      borderRadius: 6,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.border,
      overflow: 'hidden',
    },
    fill: { height: '100%', backgroundColor: theme.primary, borderRadius: 6 },
    footer: { fontSize: 12, fontWeight: '600', color: theme.textMuted, marginTop: spacing.sm },
  });
}
