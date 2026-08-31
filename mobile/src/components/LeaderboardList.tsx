import React from 'react';
import { View, Text, StyleSheet, FlatList, StyleProp, ViewStyle } from 'react-native';
import { LeaderboardEntry } from '../types/guess';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  entries: LeaderboardEntry[];
  /**
   * Pass { flex: 1 } when this list is the main content of a non-scrolling
   * screen, so it is bounded and scrolls internally instead of clipping its
   * last rows. Leave unset when nested inside a ScrollView (e.g. the top-3
   * preview on the tournament detail screen).
   */
  style?: StyleProp<ViewStyle>;
}

function getRankEmoji(rank: number) {
  if (rank === 1) return '🥇';
  if (rank === 2) return '🥈';
  if (rank === 3) return '🥉';
  return `#${rank}`;
}

function EntryRow({ item }: { item: LeaderboardEntry }) {
  return (
    <View style={[styles.row, item.is_current_user && styles.rowHighlight]}>
      <Text style={styles.rank}>{getRankEmoji(item.rank)}</Text>
      <View style={styles.info}>
        <Text style={[styles.name, item.is_current_user && styles.nameHighlight]}>
          {item.name}{item.is_current_user ? ' (you)' : ''}
        </Text>
        <Text style={styles.username}>@{item.username}</Text>
      </View>
      <View style={styles.scoreBox}>
        <Text style={[styles.score, item.is_current_user && styles.scoreHighlight]}>{item.total_score}</Text>
        <Text style={styles.guesses}>avg {item.avg_score} · {item.guesses_count} rounds</Text>
      </View>
    </View>
  );
}

export function LeaderboardList({ entries, style }: Props) {
  if (entries.length === 0) {
    return (
      <View style={styles.emptyWrap}>
        <Text style={styles.emptyIcon}>🏆</Text>
        <Text style={styles.emptyTitle}>No scores yet</Text>
        <Text style={styles.emptyText}>Play some rounds to see the leaderboard!</Text>
      </View>
    );
  }
  return (
    <FlatList
      data={entries}
      keyExtractor={(item) => String(item.user_id)}
      renderItem={({ item }) => <EntryRow item={item} />}
      ItemSeparatorComponent={() => <View style={styles.separator} />}
      style={style}
      contentContainerStyle={styles.listContent}
    />
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.md, paddingHorizontal: spacing.md },
  rowHighlight: { backgroundColor: colors.surfaceElevated },
  rank: { fontSize: 22, width: 44, textAlign: 'center' },
  info: { flex: 1, marginLeft: spacing.sm },
  name: { fontSize: 15, fontWeight: '700', color: colors.text },
  nameHighlight: { color: colors.primary },
  username: { fontSize: 12, color: colors.textSecondary },
  scoreBox: { alignItems: 'flex-end' },
  score: { fontSize: 20, fontWeight: '700', color: colors.primary },
  scoreHighlight: { color: colors.warning },
  guesses: { fontSize: 11, color: colors.textMuted },
  separator: { height: 1, backgroundColor: colors.border, marginLeft: spacing.md },
  listContent: { paddingBottom: spacing.md },
  emptyWrap: { padding: spacing.xxl, alignItems: 'center' },
  emptyIcon: { fontSize: 48, marginBottom: spacing.md },
  emptyTitle: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  emptyText: { fontSize: 14, color: colors.textSecondary, textAlign: 'center' },
});
