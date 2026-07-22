import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { RankUp } from '../types/auth';

/**
 * Premium rank-up moment shown on result screens when a guess pushed the player
 * to a new rank. Static (no animation dependency), sporty, non-blocking.
 */
export function RankUpCard({ rankUp }: { rankUp: RankUp }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={styles.card}>
      <View style={styles.badge}>
        <Text style={styles.badgeText}>{rankUp.new_level}</Text>
      </View>
      <Text style={styles.kicker}>RANK UP!</Text>
      <Text style={styles.title}>You reached {rankUp.to_rank}</Text>
      <Text style={styles.sub}>Level {rankUp.new_level} · from {rankUp.from_rank}</Text>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surfaceElevated,
      borderRadius: 16,
      borderWidth: 2,
      borderColor: theme.gold,
      padding: spacing.lg,
      marginTop: spacing.md,
      alignItems: 'center',
    },
    badge: {
      width: 56,
      height: 56,
      borderRadius: 16,
      backgroundColor: theme.gold,
      alignItems: 'center',
      justifyContent: 'center',
      marginBottom: spacing.sm,
    },
    badgeText: { fontSize: 26, fontWeight: '900', color: '#1a1400' },
    kicker: { fontSize: 12, fontWeight: '900', letterSpacing: 2, color: theme.gold },
    title: { fontSize: 20, fontWeight: '800', color: theme.text, marginTop: 2 },
    sub: { fontSize: 13, fontWeight: '600', color: theme.textSecondary, marginTop: 2 },
  });
}
