import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { XpEvent } from '../types/auth';

/** Compact "Recent XP" activity list for the profile. */
export function RecentXpCard({ events }: { events: XpEvent[] }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  if (!events.length) return null;

  return (
    <View style={styles.card}>
      {events.map((e, i) => (
        <View key={e.id} style={[styles.row, i > 0 && styles.divider]}>
          <Text style={styles.amount}>+{e.amount.toLocaleString('en-US')}</Text>
          <Text style={styles.reason} numberOfLines={1}>{e.reason}</Text>
        </View>
      ))}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surface,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.border,
      paddingHorizontal: spacing.md,
      marginBottom: spacing.xl,
    },
    row: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.sm, gap: spacing.md },
    divider: { borderTopWidth: 1, borderTopColor: theme.border },
    amount: { fontSize: 14, fontWeight: '800', color: theme.score, minWidth: 56 },
    reason: { fontSize: 13, color: theme.textSecondary, flex: 1 },
  });
}
