import React from 'react';
import { Pressable, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  onPress: () => void;
  /**
   * 'themed' follows the user's selected theme (pack / daily screens).
   * 'static' uses the fixed palette the result screens already use, so
   * nothing changes visually there.
   */
  variant?: 'themed' | 'static';
  /** Tighter padding for the guess screens, where vertical space is scarce. */
  compact?: boolean;
}

/** The single "View fullscreen" button. Used by every image surface. */
export function FullscreenButton({ onPress, variant = 'themed', compact = false }: Props) {
  const { theme } = useTheme();
  const surface = variant === 'static' ? colors.surface : theme.surface;
  const border  = variant === 'static' ? colors.border  : theme.border;
  const accent  = variant === 'static' ? colors.primary : theme.primary;

  return (
    <Pressable
      onPress={onPress}
      style={[
        styles.button,
        compact ? styles.compact : styles.regular,
        { backgroundColor: surface, borderColor: border },
      ]}
      accessibilityRole="button"
      accessibilityLabel="View fullscreen"
    >
      <Text style={[styles.text, compact && styles.textCompact, { color: accent }]}>
        ⛶  View fullscreen
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: { alignSelf: 'center', marginTop: spacing.sm, borderRadius: 10, borderWidth: 1 },
  regular: { paddingVertical: spacing.sm, paddingHorizontal: spacing.lg },
  compact: { paddingVertical: spacing.xs, paddingHorizontal: spacing.md },
  text: { fontSize: 14, fontWeight: '700' },
  textCompact: { fontSize: 13 },
});
