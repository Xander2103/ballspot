import React from 'react';
import { TextInput, View, Text, StyleSheet, TextInputProps } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';

interface Props extends TextInputProps {
  label?: string;
  error?: string;
}

export const AppInput = React.forwardRef<TextInput, Props>(
  ({ label, error, style, ...rest }, ref) => {
    const { theme } = useTheme();
    return (
      <View style={styles.container}>
        {label && <Text style={[styles.label, { color: theme.textSecondary }]}>{label}</Text>}
        <TextInput
          ref={ref}
          style={[
            styles.input,
            { backgroundColor: theme.surfaceElevated, color: theme.text, borderColor: theme.border },
            error ? { borderColor: theme.danger } : undefined,
            style,
          ]}
          placeholderTextColor={theme.textMuted}
          {...rest}
        />
        {error && <Text style={[styles.error, { color: theme.danger }]}>{error}</Text>}
      </View>
    );
  }
);

const styles = StyleSheet.create({
  container: { marginBottom: spacing.md },
  label: { fontSize: 13, marginBottom: spacing.xs, fontWeight: '600' },
  input: {
    height: 52,
    borderRadius: 12,
    paddingHorizontal: spacing.md,
    fontSize: 15,
    borderWidth: 1,
  },
  error: { fontSize: 12, marginTop: 4 },
});
