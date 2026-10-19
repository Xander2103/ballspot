import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';

export interface Segment<K extends string> {
  key: K;
  label: string;
}

interface Props<K extends string> {
  segments: Segment<K>[];
  value: K;
  onChange: (key: K) => void;
}

/** Compact segmented control (Active / Upcoming / Completed). */
export function SegmentedControl<K extends string>({ segments, value, onChange }: Props<K>) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <View style={styles.wrap} accessibilityRole="tablist">
      {segments.map((s) => {
        const selected = s.key === value;
        return (
          <TouchableOpacity
            key={s.key}
            style={[styles.segment, selected && styles.segmentSelected]}
            onPress={() => onChange(s.key)}
            activeOpacity={0.8}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
          >
            <Text style={[styles.label, selected && styles.labelSelected]} numberOfLines={1}>{s.label}</Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    wrap: {
      flexDirection: 'row', backgroundColor: theme.surfaceElevated, borderRadius: 10, padding: 3,
      borderWidth: 1, borderColor: theme.border,
    },
    segment: { flex: 1, paddingVertical: spacing.xs + 2, borderRadius: 8, alignItems: 'center' },
    segmentSelected: { backgroundColor: theme.surface },
    label: { fontSize: 13, fontWeight: '600', color: theme.textSecondary },
    labelSelected: { color: theme.primary, fontWeight: '700' },
  });
}
