import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { RankProgress } from '../types/auth';

/** Small "+XP earned / rank progress" card shown after a guess. */
export function RankProgressCard({ progress }: { progress: RankProgress }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const { rank } = progress;
  const pct = rank.is_max_rank ? 100 : Math.max(0, Math.min(100, rank.progress_to_next_rank_pct));

  return (
    <View style={styles.card}>
      <View style={styles.row}>
        <Text style={styles.xp}>+{progress.xp_gained.toLocaleString('en-US')} XP</Text>
        <Text style={styles.rank}>
          {rank.name}{rank.is_max_rank ? ' · Max level' : ` progress: ${pct}%`}
        </Text>
      </View>
      <View style={styles.track}>
        <View style={[styles.fill, { width: `${pct}%` }]} />
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surfaceElevated,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.md,
      marginTop: spacing.md,
    },
    row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.sm },
    xp: { fontSize: 18, fontWeight: '900', color: theme.score },
    rank: { fontSize: 13, fontWeight: '700', color: theme.textSecondary },
    track: { height: 8, borderRadius: 5, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.border, overflow: 'hidden' },
    fill: { height: '100%', backgroundColor: theme.primary, borderRadius: 5 },
  });
}
