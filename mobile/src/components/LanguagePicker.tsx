import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { SUPPORTED_LANGUAGES, LanguageCode } from '../utils/language';

interface Props {
  label?: string;
  value: LanguageCode;
  onChange: (code: LanguageCode) => void;
  disabled?: boolean;
  /** Code currently being saved (shows a subtle state on that chip). */
  savingCode?: LanguageCode | null;
}

/**
 * Compact chip row (same shape as the theme cards on Profile, minus the
 * swatches) so the picker fits under a form field without a modal or a
 * native dependency. Works on iOS, Android and web.
 */
export function LanguagePicker({ label, value, onChange, disabled, savingCode }: Props) {
  const { theme } = useTheme();

  return (
    <View style={styles.container}>
      {label ? <Text style={[styles.label, { color: theme.textSecondary }]}>{label}</Text> : null}
      <View style={styles.row} accessibilityRole="radiogroup">
        {SUPPORTED_LANGUAGES.map((lang) => {
          const active = lang.code === value;
          const saving = savingCode === lang.code;
          return (
            <TouchableOpacity
              key={lang.code}
              onPress={() => onChange(lang.code)}
              disabled={disabled || active}
              activeOpacity={0.8}
              accessibilityRole="radio"
              accessibilityState={{ selected: active, disabled: !!disabled }}
              accessibilityLabel={lang.label}
              style={[
                styles.chip,
                { backgroundColor: theme.surfaceElevated, borderColor: theme.border },
                active && { borderColor: theme.primary, backgroundColor: theme.surface },
                (disabled || saving) && styles.dim,
              ]}
            >
              <Text style={[styles.chipText, { color: active ? theme.primary : theme.text }]}>
                {lang.label}
              </Text>
            </TouchableOpacity>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { marginBottom: spacing.md },
  label: { fontSize: 13, marginBottom: spacing.xs, fontWeight: '600' },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: {
    paddingHorizontal: spacing.md,
    height: 40,
    borderRadius: 20,
    borderWidth: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chipText: { fontSize: 14, fontWeight: '700' },
  dim: { opacity: 0.6 },
});
