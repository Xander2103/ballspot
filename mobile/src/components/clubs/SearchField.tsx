import React from 'react';
import { View, TextInput, TouchableOpacity, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { SEARCH_QUERY_MAX } from '../../utils/paginatedSearch';

interface Props {
  value: string;
  onChangeText: (text: string) => void;
  onClear: () => void;
  placeholder: string;
  accessibilityLabel: string;
  clearLabel: string;
  /** A search is in flight: small spinner, results stay visible. */
  busy?: boolean;
}

/** Prominent search box with a clear button (server-side search; debounced by the caller's hook). */
export function SearchField({ value, onChangeText, onClear, placeholder, accessibilityLabel, clearLabel, busy }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <View style={styles.wrap}>
      <Text style={styles.icon}>🔍</Text>
      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={theme.textMuted}
        style={styles.input}
        autoCorrect={false}
        autoCapitalize="none"
        returnKeyType="search"
        maxLength={SEARCH_QUERY_MAX}
        accessibilityLabel={accessibilityLabel}
        clearButtonMode="never"
      />
      {busy ? <ActivityIndicator size="small" color={theme.textMuted} style={styles.spinner} /> : null}
      {value.length > 0 ? (
        <TouchableOpacity
          onPress={onClear}
          hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
          accessibilityRole="button"
          accessibilityLabel={clearLabel}
          style={styles.clear}
        >
          <Text style={styles.clearText}>✕</Text>
        </TouchableOpacity>
      ) : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    wrap: {
      flexDirection: 'row',
      alignItems: 'center',
      height: 48,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.border,
      backgroundColor: theme.surfaceElevated,
      paddingHorizontal: spacing.md,
      gap: spacing.sm,
    },
    icon: { fontSize: 15 },
    input: { flex: 1, height: '100%', fontSize: 15, color: theme.text },
    spinner: { marginLeft: spacing.xs },
    clear: {
      width: 24, height: 24, borderRadius: 12, alignItems: 'center', justifyContent: 'center',
      backgroundColor: theme.border,
    },
    clearText: { fontSize: 12, fontWeight: '800', color: theme.textSecondary },
  });
}
