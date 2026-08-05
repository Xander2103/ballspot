import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

export interface EmptyStateAction {
  label: string;
  onPress: () => void;
}

interface Props {
  /** Optional small glyph. Kept modest — big icons read as an empty landing page. */
  icon?: string;
  title?: string;
  message?: string;
  /** Rendered as compact pills, side by side. */
  actions?: EmptyStateAction[];
  /** Even tighter: for use inside an existing card or collapsible section. */
  compact?: boolean;
}

/**
 * One compact empty / error state used across the app, so "nothing here yet"
 * always looks the same and never becomes a large blank panel.
 */
export function EmptyState({ icon, title, message, actions, compact = false }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={[styles.wrap, compact && styles.wrapCompact]}>
      {icon ? <Text style={styles.icon}>{icon}</Text> : null}
      {title ? <Text style={styles.title}>{title}</Text> : null}
      {message ? <Text style={styles.message}>{message}</Text> : null}
      {actions?.length ? (
        <View style={styles.actions}>
          {actions.map((a) => (
            <TouchableOpacity
              key={a.label}
              onPress={a.onPress}
              style={styles.pill}
              activeOpacity={0.8}
              accessibilityRole="button"
            >
              <Text style={styles.pillText}>{a.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
      ) : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    wrap: { paddingVertical: spacing.lg, paddingHorizontal: spacing.md, alignItems: 'center' },
    wrapCompact: { paddingVertical: spacing.sm, paddingHorizontal: 0 },
    icon: { fontSize: 26, marginBottom: spacing.xs },
    title: { fontSize: 15, fontWeight: '700', color: theme.text, marginBottom: 2, textAlign: 'center' },
    message: { fontSize: 13, color: theme.textSecondary, textAlign: 'center', lineHeight: 19 },
    actions: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: spacing.sm, marginTop: spacing.md },
    pill: {
      borderRadius: 999,
      borderWidth: 1,
      borderColor: theme.border,
      backgroundColor: theme.surfaceElevated,
      paddingHorizontal: spacing.md,
      paddingVertical: spacing.sm,
    },
    pillText: { fontSize: 13, fontWeight: '700', color: theme.primary },
  });
}
