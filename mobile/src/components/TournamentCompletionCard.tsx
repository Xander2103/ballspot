import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import type { TournamentCompletion } from '../types/guess';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

function ordinal(n: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] ?? s[v] ?? s[0]);
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
  if (!completion?.is_completed) return null;

  const { placement, total_players, xp_awarded } = completion;
  const isPodium = placement <= 3;
  const accent = placement === 1 ? colors.warning : isPodium ? colors.accent : colors.textSecondary;

  const subtitle = placement === 1
    ? 'Tournament winner!'
    : isPodium
      ? 'Podium finish'
      : 'Tournament complete';

  return (
    <View style={[styles.card, { borderColor: accent + '80' }]}>
      <Text style={styles.kicker}>Tournament complete</Text>
      <Text style={styles.medal}>{medal(placement)}</Text>
      <Text style={[styles.placement, { color: accent }]}>
        You finished {ordinal(placement)} of {total_players}
      </Text>
      <Text style={styles.subtitle}>{subtitle}</Text>
      {xp_awarded > 0 ? (
        <Text style={styles.xp}>+{xp_awarded.toLocaleString('en-US')} XP</Text>
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
