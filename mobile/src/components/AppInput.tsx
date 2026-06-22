import React from 'react';
import { TextInput, View, Text, StyleSheet, TextInputProps } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props extends TextInputProps {
  label?: string;
  error?: string;
}

export const AppInput = React.forwardRef<TextInput, Props>(
  ({ label, error, style, ...rest }, ref) => {
    return (
      <View style={styles.container}>
        {label && <Text style={styles.label}>{label}</Text>}
        <TextInput
          ref={ref}
          style={[styles.input, error ? styles.inputError : undefined, style]}
          placeholderTextColor={colors.textMuted}
          {...rest}
        />
        {error && <Text style={styles.error}>{error}</Text>}
      </View>
    );
  }
);

const styles = StyleSheet.create({
  container: { marginBottom: spacing.md },
  label: { fontSize: 13, color: colors.textSecondary, marginBottom: spacing.xs, fontWeight: '600' },
  input: {
    height: 52,
    borderRadius: 12,
    backgroundColor: colors.surfaceElevated,
    paddingHorizontal: spacing.md,
    color: colors.text,
    fontSize: 15,
    borderWidth: 1,
    borderColor: colors.border,
  },
  inputError: { borderColor: colors.error },
  error: { color: colors.error, fontSize: 12, marginTop: 4 },
});
