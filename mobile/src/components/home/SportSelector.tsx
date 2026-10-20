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
/** Sport glyph size; the chip is sized around it (≈ 23% lower than the first redesign). */
const ICON_SIZE = 20;

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
                    <SportIcon slug={sport.slug} emoji={sport.emoji} color={iconColor} size={ICON_SIZE} />
                  </View>
                )}
                <Text
                  style={[styles.name, isSelected && styles.nameSelected]}
                  numberOfLines={1}
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
    row: { gap: spacing.sm, paddingVertical: 2 },
    // Still ≥ 44pt high and ≥ 64pt wide: a comfortable touch target.
    chip: {
      minWidth: 64, minHeight: 50,
      alignItems: 'center', justifyContent: 'center', gap: 2,
      paddingHorizontal: spacing.sm + 2, paddingVertical: 5,
      borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      backgroundColor: theme.surface,
    },
    // Subtle: a softened green edge and the faintest tint, no glow.
    chipSelected: { borderColor: theme.primary + 'b3', backgroundColor: theme.primary + '14' },
    // Coming soon: visible but clearly quieter than a sport you can play.
    chipSoon: { opacity: 0.55 },
    placeholder: { opacity: 0.5 },
    iconSlot: { height: ICON_SIZE, alignItems: 'center', justifyContent: 'center' },
    name: { fontSize: 12, lineHeight: 15, fontWeight: '600', color: theme.textSecondary },
    nameSelected: { color: theme.text, fontWeight: '700' },
    soon: { fontSize: 8.5, lineHeight: 10, fontWeight: '800', letterSpacing: 0.3, color: theme.textMuted, marginTop: -1 },
    message: { fontSize: 12, marginTop: spacing.xs + 2 },
    fallback: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surface, borderRadius: 14, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm + 4,
    },
    fallbackText: { fontSize: 15, fontWeight: '700', color: theme.text },
    fallbackAction: { fontSize: 13, fontWeight: '700', color: theme.primary },
  });
}
