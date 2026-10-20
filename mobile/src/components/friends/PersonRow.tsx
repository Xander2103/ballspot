import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { Avatar } from '../Avatar';

interface Props {
  name: string;
  subtitle: string;
  avatarUrl: string | null;
  /** Makes the whole row tappable (e.g. open the profile). */
  onPress?: () => void;
  /** Right-hand actions. */
  children?: React.ReactNode;
  avatarSize?: number;
  subtitleTone?: 'default' | 'danger';
  testID?: string;
}

/** One player row: avatar (photo or initials), name, one line of context, actions. */
export function PersonRow({ name, subtitle, avatarUrl, onPress, children, avatarSize = 40, subtitleTone = 'default', testID }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const body = (
    <>
      <Avatar uri={avatarUrl} name={name} size={avatarSize} ring={theme.border} />
      <View style={styles.text}>
        <Text style={styles.name} numberOfLines={1}>{name}</Text>
        <Text style={[styles.sub, subtitleTone === 'danger' && { color: theme.danger }]} numberOfLines={1}>{subtitle}</Text>
      </View>
      {children}
    </>
  );
  return onPress ? (
    <TouchableOpacity style={styles.row} onPress={onPress} activeOpacity={0.75} testID={testID}>{body}</TouchableOpacity>
  ) : (
    <View style={styles.row} testID={testID}>{body}</View>
  );
}

/** Small pill button for row actions (Accept, Decline, Add); `text` is the quietest (Remove on every friend row). */
export function RowButton({ label, onPress, tone, disabled, accessibilityLabel, testID }: {
  label: string;
  onPress: () => void;
  tone: 'primary' | 'quiet' | 'text';
  disabled?: boolean;
  accessibilityLabel?: string;
  testID?: string;
}) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <TouchableOpacity
      onPress={(e) => { e.stopPropagation(); onPress(); }}
      disabled={disabled}
      style={[styles.btn, tone === 'primary' ? styles.btnPrimary : tone === 'quiet' ? styles.btnQuiet : styles.btnPlain, disabled && styles.btnDisabled]}
      hitSlop={{ top: 6, bottom: 6, left: 4, right: 4 }}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: !!disabled }}
      testID={testID}
    >
      <Text
        style={[styles.btnText, tone === 'primary' ? styles.btnTextPrimary : tone === 'quiet' ? styles.btnTextQuiet : styles.btnTextMuted]}
        maxFontSizeMultiplier={1.3}
      >
        {label}
      </Text>
    </TouchableOpacity>
  );
}

/** Non-interactive status pill (Pending, Friends). */
export function StatusPill({ label }: { label: string }) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <View style={styles.pill}>
      <Text style={styles.pillText} maxFontSizeMultiplier={1.3}>{label}</Text>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm + 4, paddingVertical: spacing.sm + 2, minHeight: 60 },
    text: { flex: 1, minWidth: 0 },
    name: { fontSize: 15, fontWeight: '700', color: theme.text },
    sub: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    btn: { minHeight: 32, paddingHorizontal: 12, borderRadius: 9, alignItems: 'center', justifyContent: 'center', borderWidth: 1 },
    btnPrimary: { backgroundColor: theme.primary, borderColor: theme.primary },
    btnQuiet: { backgroundColor: 'transparent', borderColor: theme.border },
    btnPlain: { backgroundColor: 'transparent', borderColor: 'transparent', paddingHorizontal: 6 },
    btnDisabled: { opacity: 0.5 },
    btnText: { fontSize: 13, fontWeight: '700' },
    btnTextPrimary: { color: theme.onPrimary },
    btnTextQuiet: { color: theme.textSecondary },
    btnTextMuted: { color: theme.textMuted, fontWeight: '600' },
    pill: {
      paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999,
      backgroundColor: theme.surfaceElevated, borderWidth: 1, borderColor: theme.border,
    },
    pillText: { fontSize: 12, fontWeight: '700', color: theme.textSecondary },
  });
}
