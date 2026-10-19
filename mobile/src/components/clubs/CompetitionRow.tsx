import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import type { CompetitionCard } from '../../types/club';
import { competitionSubtitle } from '../../utils/clubDisplay';

/** One competition in the club detail section and the competitions list. */
export const CompetitionRow = React.memo(function CompetitionRow({ competition, onPress }: { competition: CompetitionCard; onPress: () => void }) {
  const { theme } = useTheme();
  const { locale } = useI18n();
  const styles = createStyles(theme);
  const icon = competition.phase === 'upcoming' ? '🗓️' : competition.phase === 'active' ? '🏆' : '🏁';
  return (
    <TouchableOpacity style={styles.row} onPress={onPress} activeOpacity={0.85} accessibilityRole="button">
      <Text style={styles.icon}>{icon}</Text>
      <View style={styles.body}>
        <Text style={styles.name} numberOfLines={1}>{competition.name}</Text>
        <Text style={styles.meta} numberOfLines={1}>{competitionSubtitle(competition, locale)}</Text>
      </View>
      <Text style={styles.chevron}>›</Text>
    </TouchableOpacity>
  );
});

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
      backgroundColor: theme.surface, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      padding: spacing.md, marginBottom: spacing.sm,
    },
    icon: { fontSize: 20 },
    body: { flex: 1, minWidth: 0 },
    name: { fontSize: 15, fontWeight: '700', color: theme.text },
    meta: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    chevron: { fontSize: 22, color: theme.textMuted },
  });
}
