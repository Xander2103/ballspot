import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { LineIcon, LineIconName } from '../LineIcon';

export interface StatTileItem {
  key: string;
  label: string;
  value: string;
  icon?: LineIconName;
  /** Value in the score colour (the headline number). */
  highlight?: boolean;
  onPress?: () => void;
  testID?: string;
}

/** Three at-a-glance numbers in one slim row; each tile opens its stats section. */
export function StatTiles({ items }: { items: StatTileItem[] }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={styles.row}>
      {items.map((item) => (
        <TouchableOpacity
          key={item.key}
          style={styles.tile}
          onPress={item.onPress}
          disabled={!item.onPress}
          activeOpacity={0.75}
          accessibilityRole="button"
          accessibilityLabel={`${item.label}: ${item.value}`}
          testID={item.testID}
        >
          <View style={styles.valueRow}>
            {item.icon ? <LineIcon name={item.icon} color={item.highlight ? theme.score : theme.textSecondary} size={14} /> : null}
            <Text
              style={[styles.value, item.highlight && { color: theme.score }]}
              numberOfLines={1}
              adjustsFontSizeToFit
              minimumFontScale={0.7}
              maxFontSizeMultiplier={1.3}
            >
              {item.value}
            </Text>
          </View>
          <Text style={styles.label} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.75} maxFontSizeMultiplier={1.3}>
            {item.label}
          </Text>
        </TouchableOpacity>
      ))}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: { flexDirection: 'row', gap: spacing.sm },
    tile: {
      flex: 1, minHeight: 60, alignItems: 'center', justifyContent: 'center',
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm + 2, paddingHorizontal: spacing.xs + 2,
    },
    valueRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    value: { fontSize: 18, fontWeight: '800', color: theme.text, fontVariant: ['tabular-nums'] },
    label: { fontSize: 11, fontWeight: '600', color: theme.textSecondary, marginTop: 2 },
  });
}
