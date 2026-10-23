import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { LineIcon, LineIconName } from '../LineIcon';

interface Props {
  icon: LineIconName;
  title: string;
  subtitle: string;
  onPress: () => void;
  disabled?: boolean;
  testID?: string;
}

/**
 * Compact secondary action (Share BallPicker / Rate BallPicker): two of these
 * sit side by side. Deliberately quieter than the achievement rows.
 */
export function ActionCard({ icon, title, subtitle, onPress, disabled, testID }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <TouchableOpacity
      style={[styles.card, disabled && styles.disabled]}
      onPress={onPress}
      disabled={disabled}
      activeOpacity={0.8}
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${subtitle}`}
      accessibilityState={{ disabled: !!disabled }}
      testID={testID}
    >
      <View style={styles.iconBox}>
        <LineIcon name={icon} color={theme.primary} size={18} />
      </View>
      <View style={styles.text}>
        <Text style={styles.title} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8} maxFontSizeMultiplier={1.3}>{title}</Text>
        <Text style={styles.subtitle} numberOfLines={2} maxFontSizeMultiplier={1.3}>{subtitle}</Text>
      </View>
    </TouchableOpacity>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      flex: 1, flexDirection: 'row', alignItems: 'center', gap: spacing.sm + 2,
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm + 2, paddingHorizontal: spacing.sm + 4, minHeight: 60,
    },
    disabled: { opacity: 0.6 },
    iconBox: { width: 32, height: 32, borderRadius: 9, backgroundColor: theme.primary + '1a', alignItems: 'center', justifyContent: 'center' },
    text: { flex: 1, minWidth: 0 },
    title: { fontSize: 14, fontWeight: '700', color: theme.text },
    subtitle: { fontSize: 11, lineHeight: 14, color: theme.textSecondary, marginTop: 2 },
  });
}
