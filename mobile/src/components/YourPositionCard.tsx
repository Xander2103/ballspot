import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  rank: number | null;
  totalPlayers: number;
  betterThanPercentage: number | null;
  score?: number | null;
}

/**
 * "You are #X of Y — closer than Z% of players" summary card used at the top
 * of leaderboard screens.
 */
export function YourPositionCard({ rank, totalPlayers, betterThanPercentage, score }: Props) {
  if (!rank) {
    return (
      <View style={styles.card}>
        <Text style={styles.label}>Your position</Text>
        <Text style={styles.subtle}>Play to get on the board!</Text>
      </View>
    );
  }

  return (
    <View style={styles.card}>
      <Text style={styles.label}>Your position</Text>
      <Text style={styles.rank}>
        #{rank.toLocaleString()} <Text style={styles.of}>of {totalPlayers.toLocaleString()}</Text>
      </Text>
      {typeof betterThanPercentage === 'number' && totalPlayers > 1 ? (
        <Text style={styles.better}>Better than {betterThanPercentage}% of players</Text>
      ) : null}
      {typeof score === 'number' ? <Text style={styles.subtle}>{score.toLocaleString()} pts this week</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.md,
    margin: spacing.md,
    marginBottom: spacing.sm,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.primary + '40',
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    color: colors.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: 2,
  },
  rank: { fontSize: 28, fontWeight: '800', color: colors.primary },
  of: { fontSize: 16, fontWeight: '600', color: colors.textSecondary },
  better: { fontSize: 13, fontWeight: '700', color: colors.text, marginTop: 2 },
  subtle: { fontSize: 12, color: colors.textMuted, marginTop: 2 },
});
