import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { ClubLogo } from './ClubLogo';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import type { ClubCard as ClubCardData, ClubRole } from '../../types/club';
import { clubMetaParts, roleLabel } from '../../utils/clubDisplay';

interface Props {
  club: ClubCardData;
  onPress: () => void;
}

export function roleChipColor(role: ClubRole | string, theme: ThemeTokens): string {
  if (role === 'owner') return theme.gold;
  if (role === 'admin') return theme.secondary;
  return theme.textSecondary;
}

/** One row of My Clubs. A suspended club is dimmed but still opens (read-only detail). */
export const ClubCard = React.memo(function ClubCard({ club, onPress }: Props) {
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const styles = createStyles(theme);
  const suspended = club.status === 'suspended';
  const roleColor = roleChipColor(club.role, theme);

  return (
    <TouchableOpacity
      style={[styles.card, suspended && styles.cardDimmed]}
      onPress={onPress}
      activeOpacity={0.85}
      accessibilityRole="button"
      accessibilityLabel={t('clubs.card.openA11y', { name: club.name })}
    >
      <ClubLogo uri={club.logo_url} name={club.name} emoji={club.sport?.emoji} dimmed={suspended} />
      <View style={styles.body}>
        <View style={styles.titleRow}>
          <Text style={[styles.name, suspended && styles.textDimmed]} numberOfLines={1}>{club.name}</Text>
          <Text style={[styles.roleChip, { color: roleColor, borderColor: roleColor + '66' }]}>{roleLabel(club.role, locale)}</Text>
        </View>
        {suspended ? (
          <Text style={styles.suspended} numberOfLines={1}>{t('clubs.card.suspended')}</Text>
        ) : (
          <Text style={styles.meta} numberOfLines={1}>{clubMetaParts(club, locale).join(' · ')}</Text>
        )}
      </View>
      <Text style={styles.chevron}>›</Text>
    </TouchableOpacity>
  );
});

/** Placeholder card for the first load (no spinner-on-blank-screen). */
export function ClubCardSkeleton() {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  return (
    <View style={styles.card} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <View style={styles.skeletonLogo} />
      <View style={styles.body}>
        <View style={[styles.skeletonLine, { width: '60%' }]} />
        <View style={[styles.skeletonLine, { width: '40%', marginTop: spacing.sm }]} />
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: spacing.md,
      backgroundColor: theme.surface,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.md,
      marginBottom: spacing.sm,
    },
    cardDimmed: { opacity: 0.7 },
    body: { flex: 1, minWidth: 0 },
    titleRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
    name: { flexShrink: 1, fontSize: 16, fontWeight: '700', color: theme.text },
    textDimmed: { color: theme.textSecondary },
    roleChip: {
      fontSize: 11, fontWeight: '700', borderWidth: 1, borderRadius: 999,
      paddingHorizontal: spacing.sm, paddingVertical: 1, overflow: 'hidden',
    },
    meta: { fontSize: 13, color: theme.textSecondary, marginTop: 3 },
    suspended: { fontSize: 13, color: theme.warning, marginTop: 3, fontWeight: '600' },
    chevron: { fontSize: 22, color: theme.textMuted },
    skeletonLogo: { width: 48, height: 48, borderRadius: 12, backgroundColor: theme.surfaceElevated },
    skeletonLine: { height: 12, borderRadius: 6, backgroundColor: theme.surfaceElevated },
  });
}
