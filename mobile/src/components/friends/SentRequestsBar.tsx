import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ScrollView } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { LineIcon } from '../LineIcon';
import type { FriendRequestItem } from '../../types/friend';
import { PersonRow, StatusPill } from './PersonRow';

interface Props {
  /** Open outgoing requests. The screen renders nothing at all when empty. */
  items: FriendRequestItem[];
  expanded: boolean;
  onToggle: () => void;
  /** Cap for the expanded list; it scrolls inside this box. */
  maxListHeight: number;
}

/**
 * Collapsed by default. Expanded, the list scrolls inside its own capped box,
 * so the tabs above and the active list below always stay on screen.
 *
 * No Cancel action: the API has no endpoint to withdraw a sent request yet.
 */
export function SentRequestsBar({ items, expanded, onToggle, maxListHeight }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <View style={styles.wrap} testID="friends-sent-bar">
      <TouchableOpacity
        style={styles.bar}
        onPress={onToggle}
        activeOpacity={0.8}
        accessibilityRole="button"
        accessibilityState={{ expanded }}
        accessibilityLabel={t('friends.list.sentA11y', { count: items.length })}
        testID="friends-sent-toggle"
      >
        <Text style={styles.title} maxFontSizeMultiplier={1.3}>{t('friends.list.sentTitle')}</Text>
        <View style={styles.count}>
          <Text style={styles.countText} maxFontSizeMultiplier={1.3}>{items.length}</Text>
        </View>
        <View style={expanded ? styles.chevronUp : undefined}>
          <LineIcon name="chevronDown" color={theme.textSecondary} size={18} />
        </View>
      </TouchableOpacity>
      {expanded ? (
        <ScrollView style={[styles.list, { maxHeight: maxListHeight }]} nestedScrollEnabled showsVerticalScrollIndicator>
          {items.map((item) => (
            <View key={item.id} style={styles.item}>
              <PersonRow name={item.user.name} subtitle={`@${item.user.username}`} avatarUrl={item.user.avatar_url} avatarSize={32}>
                <StatusPill label={t('friends.list.pending')} />
              </PersonRow>
            </View>
          ))}
        </ScrollView>
      ) : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    wrap: { backgroundColor: theme.surface, borderRadius: 13, borderWidth: 1, borderColor: theme.border, overflow: 'hidden' },
    bar: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: 44, paddingHorizontal: spacing.md - 2 },
    title: { flex: 1, fontSize: 14, fontWeight: '600', color: theme.text },
    count: {
      minWidth: 22, height: 22, borderRadius: 11, paddingHorizontal: 6,
      alignItems: 'center', justifyContent: 'center', backgroundColor: theme.surfaceElevated,
    },
    countText: { fontSize: 12, fontWeight: '800', color: theme.text },
    chevronUp: { transform: [{ rotate: '180deg' }] },
    list: { borderTopWidth: 1, borderTopColor: theme.border },
    item: { paddingHorizontal: spacing.md - 2 },
  });
}
