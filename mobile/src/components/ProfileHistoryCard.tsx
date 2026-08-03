import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { TournamentFinish } from '../types/badge';

interface Props {
  finishes: TournamentFinish[];
}

const MEDAL: Record<number, string> = { 1: '🥇', 2: '🥈', 3: '🥉' };

function formatDate(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Compact completed-tournament history. Sourced from tournament_finishes, so
 * entries survive the user hiding a tournament from their Home list.
 */
export function ProfileHistoryCard({ finishes }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <View style={styles.card}>
      {finishes.map((f, i) => (
        <View key={f.id} style={[styles.row, i > 0 && styles.rowDivider]}>
          <Text style={styles.medal}>{MEDAL[f.placement] ?? `#${f.placement}`}</Text>
          <View style={styles.text}>
            <Text style={styles.name} numberOfLines={1}>{f.league?.name ?? 'Tournament'}</Text>
            <Text style={styles.meta}>
              {`#${f.placement}`}
              {f.total_players ? ` of ${f.total_players}` : ''}
              {` · ${f.total_score} pts`}
              {f.rounds_played ? ` · ${f.rounds_played} rounds` : ''}
            </Text>
          </View>
          <Text style={styles.date}>{formatDate(f.completed_at)}</Text>
        </View>
      ))}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, marginBottom: spacing.xl,
    },
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: spacing.md },
    rowDivider: { borderTopWidth: 1, borderTopColor: theme.border },
    medal: { fontSize: 18, minWidth: 28, textAlign: 'center', color: theme.textSecondary, fontWeight: '700' },
    text: { flex: 1 },
    name: { fontSize: 14, fontWeight: '700', color: theme.text },
    meta: { fontSize: 12, color: theme.textSecondary, marginTop: 1 },
    date: { fontSize: 11, color: theme.textMuted },
  });
}
