import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';

export interface Segment<K extends string> {
  key: K;
  label: string;
  testID?: string;
}

interface Props<K extends string> {
  segments: Segment<K>[];
  active: K;
  onChange: (key: K) => void;
}

/**
 * Compact segmented control (same look as the Friends tabs): only the content
 * below it changes. Generic over the segment keys so screens stay typed.
 */
export function SegmentedControl<K extends string>({ segments, active, onChange }: Props<K>) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={styles.bar} accessibilityRole="tablist">
      {segments.map((segment) => {
        const selected = segment.key === active;
        return (
          <TouchableOpacity
            key={segment.key}
            style={[styles.tab, selected && styles.tabActive]}
            onPress={() => onChange(segment.key)}
            activeOpacity={0.8}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
            accessibilityLabel={segment.label}
            testID={segment.testID}
          >
            <Text
              style={[styles.label, selected && styles.labelActive]}
              numberOfLines={1}
              adjustsFontSizeToFit
              minimumFontScale={0.75}
              maxFontSizeMultiplier={1.3}
            >
              {segment.label}
            </Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    bar: {
      flexDirection: 'row', gap: 4, padding: 3,
      backgroundColor: theme.surface, borderRadius: 13, borderWidth: 1, borderColor: theme.border,
    },
    tab: {
      flex: 1, minHeight: 36, borderRadius: 10, borderWidth: 1, borderColor: 'transparent',
      alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4,
    },
    tabActive: { backgroundColor: theme.primary + '1a', borderColor: theme.primary + '4d' },
    label: { fontSize: 14, fontWeight: '600', color: theme.textSecondary, flexShrink: 1 },
    labelActive: { color: theme.primary, fontWeight: '700' },
  });
}
