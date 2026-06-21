import React from 'react';
import { View, Text, StyleSheet, FlatList } from 'react-native';
import { LeaderboardEntry } from '../types/guess';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  entries: LeaderboardEntry[];
}

function getRankEmoji(rank: number) {
  if (rank === 1) return '🥇';
  if (rank === 2) return '🥈';
  if (rank === 3) return '🥉';
  return `#${rank}`;
}

function EntryRow({ item }: { item: LeaderboardEntry }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rank}>{getRankEmoji(item.rank)}</Text>
      <View style={styles.info}>
        <Text style={styles.name}>{item.name}</Text>
        <Text style={styles.username}>@{item.username}</Text>
      </View>
      <View style={styles.scoreBox}>
        <Text style={styles.score}>{item.total_score}</Text>
        <Text style={styles.guesses}>{item.guesses_count} rounds</Text>
      </View>
    </View>
  );
}

export function LeaderboardList({ entries }: Props) {
  if (entries.length === 0) {
    return <Text style={styles.empty}>No scores yet. Play some rounds!</Text>;
  }
  return (
    <FlatList
      data={entries}
      keyExtractor={(item) => String(item.user_id)}
      renderItem={({ item }) => <EntryRow item={item} />}
      ItemSeparatorComponent={() => <View style={styles.separator} />}
    />
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.md, paddingHorizontal: spacing.md },
  rank: { fontSize: 22, width: 44, textAlign: 'center' },
  info: { flex: 1, marginLeft: spacing.sm },
  name: { fontSize: 15, fontWeight: '700', color: colors.text },
  username: { fontSize: 12, color: colors.textSecondary },
  scoreBox: { alignItems: 'flex-end' },
  score: { fontSize: 20, fontWeight: '700', color: colors.primary },
  guesses: { fontSize: 11, color: colors.textMuted },
  separator: { height: 1, backgroundColor: colors.border, marginLeft: spacing.md },
  empty: { color: colors.textSecondary, textAlign: 'center', padding: spacing.xl },
});
