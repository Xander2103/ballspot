import React from 'react';
import { TouchableOpacity, Text, StyleSheet, ActivityIndicator, ViewStyle } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';

interface Props {
  title: string;
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'primary' | 'secondary' | 'danger';
  style?: ViewStyle;
  /** Upper limit for the system text size, where the button shares tight space. */
  maxFontSizeMultiplier?: number;
}

export function AppButton({ title, onPress, loading, disabled, variant = 'primary', style, maxFontSizeMultiplier }: Props) {
  const { theme } = useTheme();
  const bg = variant === 'primary' ? theme.primary : variant === 'danger' ? theme.danger : theme.surfaceElevated;
  const textColor = variant === 'primary' ? theme.onPrimary : theme.text;

  return (
    <TouchableOpacity
      style={[styles.button, { backgroundColor: bg, opacity: disabled || loading ? 0.6 : 1 }, style]}
      onPress={onPress}
      disabled={disabled || loading}
      activeOpacity={0.8}
    >
      {loading ? (
        <ActivityIndicator color={textColor} size="small" />
      ) : (
        <Text style={[styles.text, { color: textColor }]} maxFontSizeMultiplier={maxFontSizeMultiplier}>{title}</Text>
      )}
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  button: {
    minHeight: 52,
    paddingVertical: spacing.sm,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
  },
  text: {
    fontSize: 16,
    fontWeight: '700',
    textAlign: 'center',
  },
});
