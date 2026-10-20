import React from 'react';
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, ActivityIndicator } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import type { Sport } from '../../types/sport';
import { SportIcon } from './SportIcon';

interface Props {
  /** null while the first /sports request is in flight. */
  sports: Sport[] | null;
  /** /sports failed: fall back to the full-screen picker so changing sport still works. */
  loadFailed: boolean;
  /** The user's preferred sport — the single source of truth for "selected". */
  selected: Sport | null;
  savingId: number | null;
  onTap: (sport: Sport) => void;
  onOpenPicker: () => void;
  /** Inline note under the row (coming soon / save failed). */
  message: { text: string; tone: 'info' | 'error' } | null;
  /** Horizontal inset, so the row scrolls edge to edge but starts aligned with the cards. */
  inset: number;
}

const PLACEHOLDER_CHIPS = 4;

/** Horizontal sport row. Selecting a sport saves the preference exactly like SportSelectionScreen. */
export function SportSelector({ sports, loadFailed, selected, savingId, onTap, onOpenPicker, message, inset }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  // The full-screen picker is still the way out when the row cannot load.
  if (loadFailed || (sports && sports.length === 0)) {
    return (
      <View style={{ paddingHorizontal: inset }}>
        <TouchableOpacity style={styles.fallback} activeOpacity={0.8} onPress={onOpenPicker} accessibilityRole="button">
          <Text style={styles.fallbackText}>
            {selected ? `${selected.emoji} ${selected.name}` : t('home.sportChip.pick')}
          </Text>
          <Text style={styles.fallbackAction}>{t('home.sportChip.change')}</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={[styles.row, { paddingHorizontal: inset }]}
      >
        {sports
          ? sports.map((sport) => {
            const isSelected = sport.id === selected?.id;
            const comingSoon = !sport.is_playable;
            const iconColor = isSelected ? theme.primary : theme.textSecondary;
            return (
              <TouchableOpacity
                key={sport.id}
                testID={`home-sport-${sport.slug}`}
                activeOpacity={0.8}
                onPress={() => onTap(sport)}
                disabled={savingId !== null}
                accessibilityRole="button"
                accessibilityState={{ selected: isSelected, disabled: comingSoon || savingId !== null }}
                accessibilityLabel={comingSoon ? `${sport.name}, ${t('home.sportSelection.comingSoon')}` : sport.name}
                style={[styles.chip, isSelected && styles.chipSelected, comingSoon && styles.chipSoon]}
              >
                {savingId === sport.id ? (
                  <ActivityIndicator color={theme.primary} style={styles.iconSlot} />
                ) : (
                  <View style={styles.iconSlot}>
                    <SportIcon slug={sport.slug} emoji={sport.emoji} color={iconColor} />
                  </View>
                )}
                <Text
                  style={[styles.name, isSelected && styles.nameSelected]}
                  maxFontSizeMultiplier={1.4}
                >
                  {sport.name}
                </Text>
                {comingSoon ? (
                  <Text style={styles.soon} maxFontSizeMultiplier={1.2}>{t('home.sportSelection.soonBadge')}</Text>
                ) : null}
              </TouchableOpacity>
            );
          })
          : Array.from({ length: PLACEHOLDER_CHIPS }, (_, i) => <View key={i} style={[styles.chip, styles.placeholder]} />)}
      </ScrollView>
      {message ? (
        <Text
          style={[styles.message, { paddingHorizontal: inset, color: message.tone === 'error' ? theme.danger : theme.textSecondary }]}
          accessibilityLiveRegion="polite"
        >
          {message.text}
        </Text>
      ) : null}
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: { gap: spacing.sm + 2, paddingVertical: 2 },
    chip: {
      minWidth: 76, minHeight: 64,
      alignItems: 'center', justifyContent: 'center', gap: 6,
      paddingHorizontal: spacing.md - 4, paddingVertical: spacing.sm,
      borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      backgroundColor: theme.surface,
    },
    chipSelected: { borderColor: theme.primary, backgroundColor: theme.primary + '1a' },
    // Coming soon: visible but clearly quieter than a sport you can play.
    chipSoon: { opacity: 0.55 },
    placeholder: { opacity: 0.5 },
    iconSlot: { height: 24, alignItems: 'center', justifyContent: 'center' },
    name: { fontSize: 13, fontWeight: '600', color: theme.textSecondary },
    nameSelected: { color: theme.text, fontWeight: '700' },
    soon: { fontSize: 9, fontWeight: '800', letterSpacing: 0.3, color: theme.textMuted, marginTop: -4 },
    message: { fontSize: 12, marginTop: spacing.sm },
    fallback: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm + 4,
    },
    fallbackText: { fontSize: 15, fontWeight: '700', color: theme.text },
    fallbackAction: { fontSize: 13, fontWeight: '700', color: theme.primary },
  });
}
