import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import type { Badge } from '../types/badge';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  badges: Badge[];
}

const RARITY_COLOR: Record<string, string> = {
  common: colors.textSecondary,
  rare: colors.accent,
  epic: '#b76bff',
  legendary: colors.warning,
};

const RARITY_RANK: Record<string, number> = { common: 0, rare: 1, epic: 2, legendary: 3 };

function rarityColor(rarity: string): string {
  return RARITY_COLOR[rarity] ?? colors.textSecondary;
}

/**
 * Celebratory "New badge unlocked!" card shown on result screens when a guess
 * earns one or more virtual trophies. A legendary unlock (e.g. Perfect Picker)
 * gets a distinct, premium treatment.
 */
export function NewBadgesCard({ badges }: Props) {
  if (!badges || badges.length === 0) return null;

  // Highest rarity present drives the card accent / headline.
  const topRarity = badges.reduce(
    (top, b) => (RARITY_RANK[b.rarity] ?? 0) > (RARITY_RANK[top] ?? 0) ? b.rarity : top,
    'common',
  );
  const isLegendary = topRarity === 'legendary';
  const accent = rarityColor(topRarity);

  const title = isLegendary
    ? '🏆 Legendary badge unlocked!'
    : `🎉 New badge${badges.length > 1 ? 's' : ''} unlocked!`;

  return (
    <View style={[styles.card, { borderColor: accent + '80' }]}>
      <Text style={[styles.title, { color: accent }]}>{title}</Text>
      <View style={styles.row}>
        {badges.map((b) => (
          <View key={b.code} style={styles.badge}>
            <Text style={styles.icon}>{b.icon}</Text>
            <Text style={styles.name} numberOfLines={2}>{b.name}</Text>
            <Text style={[styles.rarity, { color: rarityColor(b.rarity) }]}>{b.rarity}</Text>
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
  },
  title: {
    fontSize: 15,
    fontWeight: '800',
    textAlign: 'center',
    marginBottom: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: spacing.md,
  },
  badge: { alignItems: 'center', width: 90 },
  icon: { fontSize: 34, marginBottom: 2 },
  name: { fontSize: 12, fontWeight: '700', color: colors.text, textAlign: 'center' },
  rarity: { fontSize: 9, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5, marginTop: 2 },
});
