import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { useI18n } from '../../i18n';
import { formatBadgeCount } from '../CollapsibleSection';
import type { FriendsTab } from '../../utils/friendsModel';

interface Props {
  active: FriendsTab;
  onChange: (tab: FriendsTab) => void;
  /** Open incoming requests, shown on the Requests tab when > 0. */
  requestsCount: number;
}

const TABS: FriendsTab[] = ['friends', 'requests', 'discover'];

/** Compact segmented control; only the list below it changes. */
export function FriendsTabs({ active, onChange, requestsCount }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const labels: Record<FriendsTab, string> = {
    friends: t('friends.tabs.friends'),
    requests: t('friends.tabs.requests'),
    discover: t('friends.tabs.discover'),
  };

  return (
    <View style={styles.bar} accessibilityRole="tablist">
      {TABS.map((key) => {
        const selected = key === active;
        const badge = key === 'requests' && requestsCount > 0 ? formatBadgeCount(requestsCount) : null;
        return (
          <TouchableOpacity
            key={key}
            style={[styles.tab, selected && styles.tabActive]}
            onPress={() => onChange(key)}
            activeOpacity={0.8}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
            accessibilityLabel={badge ? t('friends.tabs.requestsA11y', { count: requestsCount }) : labels[key]}
            testID={`friends-tab-${key}`}
          >
            <Text
              style={[styles.label, selected && styles.labelActive]}
              numberOfLines={1}
              // Large text on a small phone: shrink a little rather than truncate "Requests".
              adjustsFontSizeToFit
              minimumFontScale={0.75}
              maxFontSizeMultiplier={1.3}
            >
              {labels[key]}
            </Text>
            {badge ? (
              <View style={styles.badge}>
                <Text style={styles.badgeText} maxFontSizeMultiplier={1.2}>{badge}</Text>
              </View>
            ) : null}
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    bar: {
      flexDirection: 'row', gap: 4, padding: 3,
      backgroundColor: theme.surface, borderRadius: 13, borderWidth: 1, borderColor: theme.border,
    },
    tab: {
      flex: 1, minHeight: 36, borderRadius: 10, borderWidth: 1, borderColor: 'transparent',
      flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 5, paddingHorizontal: 4,
    },
    // Subtle green: a faint tint and a soft edge, the label carries the colour.
    tabActive: { backgroundColor: theme.primary + '1a', borderColor: theme.primary + '4d' },
    label: { fontSize: 14, fontWeight: '600', color: theme.textSecondary, flexShrink: 1 },
    labelActive: { color: theme.primary, fontWeight: '700' },
    badge: {
      minWidth: 18, height: 18, borderRadius: 9, paddingHorizontal: 5,
      alignItems: 'center', justifyContent: 'center', backgroundColor: theme.danger,
    },
    badgeText: { color: '#ffffff', fontSize: 11, fontWeight: '800' },
  });
}
