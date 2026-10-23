import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ViewStyle } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { LineIcon, LineIconName } from './LineIcon';

interface GroupProps {
  /** Small uppercase heading above the card. */
  title?: string;
  children: React.ReactNode;
  style?: ViewStyle;
  testID?: string;
}

/**
 * A card of rows with hairline dividers (Profile achievements, Settings
 * groups). Rows are `ListRow`s or any custom content; `null` children are
 * skipped so conditional rows never leave a stray divider.
 */
export function ListGroup({ title, children, style, testID }: GroupProps) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const rows = React.Children.toArray(children).filter(Boolean);
  return (
    <View style={[styles.group, style]} testID={testID}>
      {title ? <Text style={styles.groupTitle} maxFontSizeMultiplier={1.4}>{title}</Text> : null}
      <View style={styles.card}>
        {rows.map((child, i) => (
          <View key={i} style={i > 0 ? styles.divider : undefined}>{child}</View>
        ))}
      </View>
    </View>
  );
}

interface RowProps {
  icon?: LineIconName;
  /** Alternative leading glyph (e.g. the sport emoji). */
  emoji?: string;
  title: string;
  subtitle?: string;
  /** Short value on the right (current setting, count). */
  value?: string;
  onPress?: () => void;
  /** Right-hand element replacing the chevron (e.g. a Switch). */
  right?: React.ReactNode;
  tone?: 'default' | 'danger';
  /** Opens outside the app: shows an "external" glyph instead of a chevron. */
  external?: boolean;
  disabled?: boolean;
  accessibilityLabel?: string;
  testID?: string;
}

/**
 * One row: tinted icon box, title, optional subtitle / value, and a chevron
 * when tappable. 56pt minimum so every row is a comfortable touch target.
 */
export function ListRow({
  icon, emoji, title, subtitle, value, onPress, right, tone = 'default', external = false, disabled, accessibilityLabel, testID,
}: RowProps) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const accent = tone === 'danger' ? theme.danger : theme.primary;

  const body = (
    <>
      {icon || emoji ? (
        <View style={[styles.iconBox, { backgroundColor: accent + '1a' }]}>
          {emoji ? (
            <Text style={styles.emoji} maxFontSizeMultiplier={1.2}>{emoji}</Text>
          ) : (
            <LineIcon name={icon as LineIconName} color={accent} size={18} />
          )}
        </View>
      ) : null}
      <View style={styles.text}>
        <Text style={[styles.title, tone === 'danger' && { color: theme.danger }]} numberOfLines={1} maxFontSizeMultiplier={1.4}>
          {title}
        </Text>
        {subtitle ? <Text style={styles.subtitle} numberOfLines={2} maxFontSizeMultiplier={1.4}>{subtitle}</Text> : null}
      </View>
      {value ? <Text style={styles.value} numberOfLines={1} maxFontSizeMultiplier={1.3}>{value}</Text> : null}
      {right ?? (onPress ? <LineIcon name={external ? 'external' : 'chevronRight'} color={theme.textMuted} size={18} /> : null)}
    </>
  );

  if (!onPress) {
    return <View style={styles.row} testID={testID}>{body}</View>;
  }
  return (
    <TouchableOpacity
      style={styles.row}
      onPress={onPress}
      disabled={disabled}
      activeOpacity={0.7}
      accessibilityRole={external ? 'link' : 'button'}
      accessibilityLabel={accessibilityLabel ?? (value ? `${title}, ${value}` : title)}
      accessibilityState={{ disabled: !!disabled }}
      testID={testID}
    >
      {body}
    </TouchableOpacity>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    group: { marginBottom: spacing.md },
    groupTitle: {
      fontSize: 12, fontWeight: '700', color: theme.textSecondary,
      letterSpacing: 1, textTransform: 'uppercase', marginBottom: spacing.sm, marginLeft: 4,
    },
    card: {
      backgroundColor: theme.surface, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      overflow: 'hidden',
    },
    divider: { borderTopWidth: 1, borderTopColor: theme.border },
    row: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm + 4,
      minHeight: 56, paddingHorizontal: spacing.md - 2, paddingVertical: spacing.sm + 2,
    },
    iconBox: { width: 32, height: 32, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
    emoji: { fontSize: 17 },
    text: { flex: 1, minWidth: 0 },
    title: { fontSize: 15, fontWeight: '700', color: theme.text },
    subtitle: { fontSize: 12, lineHeight: 16, color: theme.textSecondary, marginTop: 2 },
    value: { fontSize: 13, fontWeight: '600', color: theme.textSecondary, maxWidth: '40%' },
  });
}
