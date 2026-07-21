import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  rank: number;
  totalPlayers: number;
  betterThanPercentage: number;
}

/**
 * Dopamine-oriented rank/percentile insight shown on result screens.
 * "Closer than 82% of players" + "#3 of 128".
 */
export function RankInsight({ rank, totalPlayers, betterThanPercentage }: Props) {
  if (totalPlayers <= 1) {
    return (
      <View style={styles.box}>
        <Text style={styles.headline}>🎉 You're the first to play today!</Text>
        <Text style={styles.sub}>Come back tomorrow to climb the ranks.</Text>
      </View>
    );
  }

  const headline =
    betterThanPercentage >= 50
      ? `🎯 Closer than ${betterThanPercentage}% of players`
      : `You beat ${betterThanPercentage}% of players`;

  return (
    <View style={styles.box}>
      <Text style={styles.headline}>{headline}</Text>
      <Text style={styles.sub}>
        #{rank.toLocaleString()} of {totalPlayers.toLocaleString()}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    alignItems: 'center',
    backgroundColor: colors.surfaceElevated,
    borderRadius: 12,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.md,
  },
  headline: {
    fontSize: 16,
    fontWeight: '700',
    color: colors.primary,
    textAlign: 'center',
  },
  sub: {
    fontSize: 13,
    color: colors.textSecondary,
    marginTop: 4,
    fontWeight: '600',
  },
});
