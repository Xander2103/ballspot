import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import type { Badge } from '../types/badge';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  badges: Badge[];
}

/**
 * Celebratory "New badge unlocked!" card shown on result screens when a guess
 * earns one or more virtual trophies.
 */
export function NewBadgesCard({ badges }: Props) {
  if (!badges || badges.length === 0) return null;

  return (
    <View style={styles.card}>
      <Text style={styles.title}>
        🎉 New badge{badges.length > 1 ? 's' : ''} unlocked!
      </Text>
      <View style={styles.row}>
        {badges.map((b) => (
          <View key={b.code} style={styles.badge}>
            <Text style={styles.icon}>{b.icon}</Text>
            <Text style={styles.name} numberOfLines={2}>{b.name}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: 14,
    padding: spacing.md,
    marginBottom: spacing.md,
    borderWidth: 1,
    borderColor: colors.warning + '80',
  },
  title: {
    fontSize: 15,
    fontWeight: '800',
    color: colors.warning,
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: spacing.md,
  },
  badge: { alignItems: 'center', width: 84 },
  icon: { fontSize: 34, marginBottom: 2 },
  name: { fontSize: 12, fontWeight: '700', color: colors.text, textAlign: 'center' },
});
