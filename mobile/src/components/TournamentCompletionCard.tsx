import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import type { TournamentCompletion } from '../types/guess';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { useI18n, type TranslateParams } from '../i18n';

type Translate = (key: string, params?: TranslateParams) => string;

/** "1st", "2nd", "3rd", "4th", "11th", "21st" … with a translatable suffix. */
function ordinal(t: Translate, n: number): string {
  const s = ['th', 'st', 'nd', 'rd'] as const;
  const v = n % 100;
  const suffix = s[(v - 20) % 10] ?? s[v] ?? s[0];
  return n + t(`game.tournamentComplete.ordinal.${suffix}`);
}

function medal(placement: number): string {
  if (placement === 1) return '🏆';
  if (placement === 2) return '🥈';
  if (placement === 3) return '🥉';
  return '🎽';
}

/**
 * Premium "tournament complete" card shown once on the round result that
 * finished the tournament. Placement + XP; podium finishes feel special.
 */
export function TournamentCompletionCard({ completion }: { completion: TournamentCompletion }) {
  const { t } = useI18n();
  if (!completion?.is_completed) return null;

  const { placement, total_players, xp_awarded } = completion;
  const isPodium = placement <= 3;
  const accent = placement === 1 ? colors.warning : isPodium ? colors.accent : colors.textSecondary;

  const subtitle = placement === 1
    ? t('game.tournamentComplete.winner')
    : isPodium
      ? t('game.tournamentComplete.podium')
      : t('game.tournamentComplete.kicker');

  return (
    <View style={[styles.card, { borderColor: accent + '80' }]}>
      <Text style={styles.kicker}>{t('game.tournamentComplete.kicker')}</Text>
      <Text style={styles.medal}>{medal(placement)}</Text>
      <Text style={[styles.placement, { color: accent }]}>
        {t('game.tournamentComplete.finished', { placement: ordinal(t, placement), total: total_players })}
      </Text>
      <Text style={styles.subtitle}>{subtitle}</Text>
      {xp_awarded > 0 ? (
        <Text style={styles.xp}>{t('game.rankProgress.xpGained', { xp: xp_awarded.toLocaleString('en-US') })}</Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: 16,
    padding: spacing.lg,
    marginBottom: spacing.md,
    borderWidth: 1,
    alignItems: 'center',
  },
  kicker: {
    fontSize: 11,
    fontWeight: '700',
    color: colors.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: spacing.xs,
  },
  medal: { fontSize: 44, marginBottom: 2 },
  placement: { fontSize: 20, fontWeight: '800', textAlign: 'center' },
  subtitle: { fontSize: 14, fontWeight: '600', color: colors.text, marginTop: 2 },
  xp: { fontSize: 15, fontWeight: '800', color: colors.success, marginTop: spacing.sm },
});
