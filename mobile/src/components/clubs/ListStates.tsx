import React from 'react';
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';

interface FooterProps {
  loading: boolean;
  /** Error of the last "load more" (shows the inline retry row). */
  errorText?: string | null;
  retryLabel: string;
  onRetry: () => void;
  loadingLabel?: string;
}

/** FlatList footer: spinner while the next page loads, or an inline retry row (loaded items stay). */
export function PageFooter({ loading, errorText, retryLabel, onRetry, loadingLabel }: FooterProps) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  if (loading) {
    return (
      <View style={styles.footer} accessibilityLabel={loadingLabel}>
        <ActivityIndicator color={theme.primary} />
      </View>
    );
  }
  if (errorText) {
    return (
      <View style={styles.retryRow}>
        <Text style={styles.retryText} numberOfLines={2}>{errorText}</Text>
        <TouchableOpacity onPress={onRetry} style={styles.retryBtn} accessibilityRole="button">
          <Text style={styles.retryBtnText}>{retryLabel}</Text>
        </TouchableOpacity>
      </View>
    );
  }
  return <View style={styles.spacer} />;
}

/** One-line notice above a list (refresh failed, joined a club, …). */
export function InlineBanner({ text, tone = 'info' }: { text: string; tone?: 'info' | 'warning' | 'success' | 'error' }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const color = tone === 'warning' ? theme.warning : tone === 'success' ? theme.success : tone === 'error' ? theme.danger : theme.textSecondary;
  return (
    <View style={[styles.banner, { borderColor: color + '66', backgroundColor: color + '14' }]}>
      <Text style={[styles.bannerText, { color: tone === 'info' ? theme.textSecondary : color }]}>{text}</Text>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    footer: { paddingVertical: spacing.lg, alignItems: 'center' },
    spacer: { height: spacing.lg },
    retryRow: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
      marginVertical: spacing.md, padding: spacing.md, borderRadius: 12,
      backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.border,
    },
    retryText: { flex: 1, fontSize: 13, color: theme.textSecondary },
    retryBtn: {
      borderRadius: 999, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      paddingHorizontal: spacing.md, paddingVertical: spacing.xs,
    },
    retryBtnText: { fontSize: 13, fontWeight: '700', color: theme.primary },
    banner: { borderWidth: 1, borderRadius: 12, padding: spacing.sm, paddingHorizontal: spacing.md, marginBottom: spacing.sm },
    bannerText: { fontSize: 13, lineHeight: 18 },
  });
}
