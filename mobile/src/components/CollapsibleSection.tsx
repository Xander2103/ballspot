import React, { useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, LayoutAnimation, Platform, UIManager } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

// LayoutAnimation on old-architecture Android is opt-in.
if (Platform.OS === 'android' && UIManager.setLayoutAnimationEnabledExperimental) {
  UIManager.setLayoutAnimationEnabledExperimental(true);
}

interface Props {
  title: string;
  /** Small hint rendered next to the title while collapsed (e.g. a count). */
  summary?: string;
  /** Attention count (e.g. pending requests). Renders a red pill, capped at "10+". */
  badgeCount?: number;
  children: React.ReactNode;
  initiallyExpanded?: boolean;
}

/** "1".."10", then "10+" — shared cap so section and tab badges agree. */
export function formatBadgeCount(n: number): string {
  return n > 10 ? '10+' : String(n);
}

/**
 * A tappable section header that shows/hides its content. Collapsed by
 * default so long screens (Profile) stay short and scannable.
 */
export function CollapsibleSection({ title, summary, badgeCount, children, initiallyExpanded = false }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const [expanded, setExpanded] = useState(initiallyExpanded);

  function toggle() {
    LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
    setExpanded((e) => !e);
  }

  return (
    <View style={styles.wrap}>
      <TouchableOpacity
        style={styles.header}
        onPress={toggle}
        activeOpacity={0.7}
        accessibilityRole="button"
        accessibilityState={{ expanded }}
        accessibilityLabel={`${title} section`}
      >
        <Text style={styles.title}>{title}</Text>
        <View style={styles.headerRight}>
          {badgeCount && badgeCount > 0 ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{formatBadgeCount(badgeCount)}</Text>
            </View>
          ) : null}
          {!expanded && summary ? <Text style={styles.summary}>{summary}</Text> : null}
          <Text style={[styles.chevron, expanded && styles.chevronOpen]}>›</Text>
        </View>
      </TouchableOpacity>
      {expanded ? <View style={styles.body}>{children}</View> : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    wrap: {
      backgroundColor: theme.surface,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.border,
      marginBottom: spacing.sm,
      overflow: 'hidden',
    },
    header: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      paddingHorizontal: spacing.md, paddingVertical: spacing.md,
    },
    title: { fontSize: 15, fontWeight: '700', color: theme.text },
    headerRight: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    summary: { fontSize: 13, color: theme.textMuted },
    badge: {
      minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 5,
      backgroundColor: theme.danger, alignItems: 'center', justifyContent: 'center',
    },
    badgeText: { fontSize: 11, fontWeight: '800', color: '#ffffff' },
    chevron: { fontSize: 18, fontWeight: '700', color: theme.textMuted, transform: [{ rotate: '90deg' }] },
    chevronOpen: { transform: [{ rotate: '-90deg' }] },
    body: { paddingHorizontal: spacing.md, paddingBottom: spacing.md },
  });
}
