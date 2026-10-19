import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

interface Props {
  rank: number | null;
  totalPlayers: number;
  betterThanPercentage: number | null;
  score?: number | null;
  /**
   * The score line, already translated. Defaults to the weekly
   * "{{score}} pts this week" copy the existing boards use.
   */
  scoreText?: string | null;
  /** Another player has the same rank (shared ranks: 1, 2, 2, 4) — shows "=4". */
  shared?: boolean;
}

/**
 * "You are #X of Y — closer than Z% of players" summary card used at the top
 * of leaderboard screens.
 */
export function YourPositionCard({ rank, totalPlayers, betterThanPercentage, score, scoreText, shared = false }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  if (!rank) {
    return (
      <View style={styles.card}>
        <Text style={styles.label}>{t('game.position.label')}</Text>
        <Text style={styles.subtle}>{t('game.position.playToJoin')}</Text>
      </View>
    );
  }

  const scoreLine = scoreText ?? (typeof score === 'number' ? t('game.position.ptsThisWeek', { score: score.toLocaleString() }) : null);

  return (
    <View style={styles.card}>
      <Text style={styles.label}>{t('game.position.label')}</Text>
      <Text style={styles.rank}>
        {shared ? '=' : '#'}{rank.toLocaleString()} <Text style={styles.of}>{t('game.position.of', { total: totalPlayers.toLocaleString() })}</Text>
      </Text>
      {typeof betterThanPercentage === 'number' && totalPlayers > 1 ? (
        <Text style={styles.better}>{t('game.position.betterThan', { pct: betterThanPercentage })}</Text>
      ) : null}
      {scoreLine ? <Text style={styles.subtle}>{scoreLine}</Text> : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surfaceElevated,
      borderRadius: 12,
      paddingVertical: spacing.md,
      paddingHorizontal: spacing.md,
      margin: spacing.md,
      marginBottom: spacing.sm,
      alignItems: 'center',
      borderWidth: 1,
      borderColor: theme.primary + '40',
    },
    label: {
      fontSize: 11,
      fontWeight: '700',
      color: theme.textMuted,
      textTransform: 'uppercase',
      letterSpacing: 0.5,
      marginBottom: 2,
    },
    rank: { fontSize: 28, fontWeight: '800', color: theme.primary },
    of: { fontSize: 16, fontWeight: '600', color: theme.textSecondary },
    better: { fontSize: 13, fontWeight: '700', color: theme.text, marginTop: 2 },
    subtle: { fontSize: 12, color: theme.textMuted, marginTop: 2 },
  });
}
