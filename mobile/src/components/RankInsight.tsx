import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

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
  const { t } = useI18n();

  if (totalPlayers <= 1) {
    return (
      <View style={styles.box}>
        <Text style={styles.headline}>{t('game.insight.firstToday')}</Text>
        <Text style={styles.sub}>{t('game.insight.comeBack')}</Text>
      </View>
    );
  }

  const headline =
    betterThanPercentage >= 50
      ? t('game.insight.closerThan', { pct: betterThanPercentage })
      : t('game.insight.beat', { pct: betterThanPercentage });

  return (
    <View style={styles.box}>
      <Text style={styles.headline}>{headline}</Text>
      <Text style={styles.sub}>
        {t('game.insight.rankOf', { rank: rank.toLocaleString(), total: totalPlayers.toLocaleString() })}
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
