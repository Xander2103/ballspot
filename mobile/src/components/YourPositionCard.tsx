import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

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
  const { t } = useI18n();

  if (!rank) {
    return (
      <View style={styles.card}>
        <Text style={styles.label}>{t('game.position.label')}</Text>
        <Text style={styles.subtle}>{t('game.position.playToJoin')}</Text>
      </View>
    );
  }

  return (
    <View style={styles.card}>
      <Text style={styles.label}>{t('game.position.label')}</Text>
      <Text style={styles.rank}>
        #{rank.toLocaleString()} <Text style={styles.of}>{t('game.position.of', { total: totalPlayers.toLocaleString() })}</Text>
      </Text>
      {typeof betterThanPercentage === 'number' && totalPlayers > 1 ? (
        <Text style={styles.better}>{t('game.position.betterThan', { pct: betterThanPercentage })}</Text>
      ) : null}
      {typeof score === 'number' ? <Text style={styles.subtle}>{t('game.position.ptsThisWeek', { score: score.toLocaleString() })}</Text> : null}
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
