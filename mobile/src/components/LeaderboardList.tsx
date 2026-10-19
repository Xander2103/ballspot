import React from 'react';
import { View, Text, StyleSheet, FlatList, StyleProp, ViewStyle } from 'react-native';
import { Avatar } from './Avatar';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import { rankDisplay, sharedRankFlags } from '../utils/sharedRank';

/**
 * What a leaderboard row needs. Tournament entries (types/guess LeaderboardEntry)
 * carry a display name + guess stats; club entries carry a username + avatar
 * only (D12) and use `subtitle` for their stats line.
 */
export interface LeaderboardRowEntry {
  rank: number;
  user_id: number;
  username: string;
  name?: string | null;
  avatar_url?: string | null;
  total_score: number;
  is_current_user: boolean;
  guesses_count?: number;
  avg_score?: number;
}

interface RowProps<E extends LeaderboardRowEntry> {
  item: E;
  /** True when another row shares this rank (renders "=4" instead of "#4"). */
  shared?: boolean;
  /** Stats line under the name; defaults to the tournament "avg · guesses" line. */
  subtitle?: (entry: E) => string;
  showAvatar?: boolean;
}

export function LeaderboardRow<E extends LeaderboardRowEntry>({ item, shared = false, subtitle, showAvatar = false }: RowProps<E>) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const rank = rankDisplay(item.rank, shared);
  const title = item.name ? item.name : item.username;
  const stats = subtitle
    ? subtitle(item)
    : t('game.leaderboard.rowStats', { avg: item.avg_score ?? 0, count: item.guesses_count ?? 0 });

  return (
    <View style={[styles.row, item.is_current_user && styles.rowHighlight]}>
      <Text
        style={[styles.rank, !rank.isMedal && styles.rankText]}
        accessibilityLabel={shared ? t('clubs.leaderboard.sharedRankA11y', { rank: item.rank }) : t('clubs.leaderboard.rankA11y', { rank: item.rank })}
      >
        {rank.text}
      </Text>
      {showAvatar ? <Avatar uri={item.avatar_url} name={item.username} size={32} ring={theme.border} /> : null}
      <View style={styles.info}>
        <Text style={[styles.name, item.is_current_user && styles.nameHighlight]} numberOfLines={1}>
          {title}{item.is_current_user ? ` ${t('game.leaderboard.youSuffix')}` : ''}
        </Text>
        {item.name ? <Text style={styles.username}>@{item.username}</Text> : null}
        {subtitle ? <Text style={styles.stats} numberOfLines={1}>{stats}</Text> : null}
      </View>
      <View style={styles.scoreBox}>
        <Text style={[styles.score, item.is_current_user && styles.scoreHighlight]}>{item.total_score}</Text>
        {subtitle ? null : <Text style={styles.guesses}>{stats}</Text>}
      </View>
    </View>
  );
}

export function LeaderboardSeparator() {
  const { theme } = useTheme();
  return <View style={{ height: 1, backgroundColor: theme.border, marginLeft: spacing.md }} />;
}

export function LeaderboardEmpty({ title, text }: { title?: string; text?: string }) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  return (
    <View style={styles.emptyWrap}>
      <Text style={styles.emptyIcon}>🏆</Text>
      <Text style={styles.emptyTitle}>{title ?? t('game.leaderboard.emptyTitle')}</Text>
      <Text style={styles.emptyText}>{text ?? t('game.leaderboard.emptyText')}</Text>
    </View>
  );
}

interface Props<E extends LeaderboardRowEntry> {
  entries: E[];
  /**
   * Pass { flex: 1 } when this list is the main content of a non-scrolling
   * screen, so it is bounded and scrolls internally instead of clipping its
   * last rows. Leave unset when nested inside a ScrollView (e.g. the top-3
   * preview on the tournament detail screen).
   */
  style?: StyleProp<ViewStyle>;
  /** Mark tied ranks ("=4"). Off by default so existing boards render exactly as before. */
  sharedRanks?: boolean;
  subtitle?: (entry: E) => string;
  showAvatars?: boolean;
}

export function LeaderboardList<E extends LeaderboardRowEntry>({ entries, style, sharedRanks = false, subtitle, showAvatars = false }: Props<E>) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  if (entries.length === 0) return <LeaderboardEmpty />;
  const shared = sharedRanks ? sharedRankFlags(entries) : null;
  return (
    <FlatList
      data={entries}
      keyExtractor={(item) => String(item.user_id)}
      renderItem={({ item, index }) => (
        <LeaderboardRow item={item} shared={shared?.[index] ?? false} subtitle={subtitle} showAvatar={showAvatars} />
      )}
      ItemSeparatorComponent={LeaderboardSeparator}
      style={style}
      contentContainerStyle={styles.listContent}
    />
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.md, paddingHorizontal: spacing.md },
    rowHighlight: { backgroundColor: theme.surfaceElevated },
    rank: { fontSize: 22, width: 44, textAlign: 'center' },
    rankText: { fontSize: 16, fontWeight: '800', color: theme.textSecondary },
    info: { flex: 1, minWidth: 0 },
    name: { fontSize: 15, fontWeight: '700', color: theme.text },
    nameHighlight: { color: theme.primary },
    username: { fontSize: 12, color: theme.textSecondary },
    stats: { fontSize: 12, color: theme.textMuted, marginTop: 1 },
    scoreBox: { alignItems: 'flex-end' },
    score: { fontSize: 20, fontWeight: '700', color: theme.primary },
    scoreHighlight: { color: theme.warning },
    guesses: { fontSize: 11, color: theme.textMuted },
    listContent: { paddingBottom: spacing.md },
    emptyWrap: { padding: spacing.xxl, alignItems: 'center' },
    emptyIcon: { fontSize: 48, marginBottom: spacing.md },
    emptyTitle: { fontSize: 18, fontWeight: '700', color: theme.text, marginBottom: spacing.sm },
    emptyText: { fontSize: 14, color: theme.textSecondary, textAlign: 'center' },
  });
}
