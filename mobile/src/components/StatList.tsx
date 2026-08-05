import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

export interface StatItem {
  label: string;
  value: string | number;
  /** Small extra shown after the value, e.g. "best 5". */
  detail?: string;
  /** Highlight the value in the theme's score color. */
  highlight?: boolean;
}

/**
 * Compact label/value stat rows with subtle dividers — reads like a match
 * sheet instead of a grid of oversized metric boxes.
 */
export function StatList({ items }: { items: StatItem[] }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View>
      {items.map((item, i) => (
        <View key={item.label} style={[styles.row, i > 0 && styles.divider]}>
          <Text style={styles.label}>{item.label}</Text>
          <View style={styles.valueWrap}>
            <Text style={[styles.value, item.highlight && { color: theme.score }]}>{item.value}</Text>
            {item.detail ? <Text style={styles.detail}>{item.detail}</Text> : null}
          </View>
        </View>
      ))}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      paddingVertical: spacing.sm + 2, gap: spacing.md,
    },
    divider: { borderTopWidth: 1, borderTopColor: theme.border },
    label: { fontSize: 13, color: theme.textSecondary, flex: 1 },
    valueWrap: { flexDirection: 'row', alignItems: 'baseline', gap: spacing.xs + 2 },
    value: { fontSize: 15, fontWeight: '700', color: theme.text, fontVariant: ['tabular-nums'] },
    detail: { fontSize: 12, color: theme.textMuted },
  });
}
