import React from 'react';
import { View, Text, StyleSheet, ActivityIndicator, useWindowDimensions } from 'react-native';
import { Image } from 'expo-image';
import Svg, { Path, Rect } from 'react-native-svg';
import { useTheme } from '../../theme/useTheme';
import { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { AppButton } from '../AppButton';
import { CardGradient } from './CardGradient';
import type { DailyCardModel } from '../../utils/homeModel';

const dailyImage = require('../../../assets/home/ballpicker_daily_rustig.png');

interface Props {
  model: DailyCardModel;
  onPlay: (dailyChallengeId: number) => void;
  onViewResult: (dailyChallengeId: number) => void;
}

function todayDateFormatted(locale: string): string {
  // English keeps the day-first (en-GB) format the screen has always used.
  const dateLocale = locale === 'en' ? 'en-GB' : locale;
  return new Date().toLocaleDateString(dateLocale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function CalendarIcon({ color }: { color: string }) {
  return (
    <Svg width={16} height={16} viewBox="0 0 24 24">
      <Rect x={3.5} y={5} width={17} height={15.5} rx={2.5} stroke={color} strokeWidth={2} fill="none" />
      <Path d="M3.5 10h17M8 3v4M16 3v4" stroke={color} strokeWidth={2} strokeLinecap="round" />
    </Svg>
  );
}

/**
 * The main game mode: one wide card with the Daily artwork on top, fading
 * into the card so title, progress, streak and the action stay the focus.
 * The image is decoration only — every line of copy and the action come from
 * the model (utils/homeModel.ts), so no state the old card had is lost.
 */
export function DailyChallengeCard({ model, onPlay, onViewResult }: Props) {
  const { theme } = useTheme();
  const { t, locale } = useI18n();
  const { width } = useWindowDimensions();
  const styles = createStyles(theme);

  // Artwork band scales with the phone, so it never dominates a small screen
  // and never turns into a thin strip on a large one.
  const imageHeight = Math.round(Math.min(Math.max(width * 0.42, 150), 210));

  const progressLine = model.kind === 'played' || model.kind === 'playable'
    ? (model.progress ? t('home.daily.dayOf', { index: model.progress.index, total: model.progress.total }) : todayDateFormatted(locale))
    : todayDateFormatted(locale);

  return (
    <View style={styles.dailyCard} testID="home-daily-card">
      <View style={[styles.imageWrap, { height: imageHeight }]} pointerEvents="none">
        <Image
          source={dailyImage}
          style={StyleSheet.absoluteFill}
          contentFit="cover"
          // The ball sits right of centre in the wide artwork: keep it in frame.
          contentPosition={{ top: '50%', left: '78%' }}
          accessible={false}
        />
        <CardGradient id="homeDailyFade" color={theme.surface} start={0.3} />
      </View>

      <View style={[styles.body, { paddingTop: imageHeight - 56 }]}>
        <Text style={styles.title} accessibilityRole="header">{t('home.daily.cardTitle')}</Text>
        <Text style={styles.subtitle}>{t('home.daily.subtitle')}</Text>

        {model.kind === 'loading' ? (
          <View style={styles.loadingRow}>
            <ActivityIndicator color={theme.primary} size="small" />
            <Text style={styles.loadingText}>{t('home.daily.loading')}</Text>
          </View>
        ) : (
          <>
            <View style={styles.metaRow}>
              <CalendarIcon color={theme.textSecondary} />
              <Text style={styles.metaText}>{progressLine}</Text>
            </View>

            {model.kind === 'playable' && model.info ? (
              <View style={styles.pill}>
                <Text style={styles.pillText}>{model.info}</Text>
              </View>
            ) : null}
            {model.kind === 'played' ? (
              <View style={[styles.pill, styles.pillDone]}>
                <Text style={[styles.pillText, styles.pillTextDone]}>{t('home.daily.completed')}</Text>
              </View>
            ) : null}

            {(model.kind === 'played' || model.kind === 'playable') && model.streak > 0 ? (
              <Text style={styles.streak}>{t('daily.streak', { count: model.streak })}</Text>
            ) : null}

            {model.kind === 'none' ? (
              <>
                <Text style={styles.empty}>
                  {model.isOtherSport
                    ? t('home.daily.noneForSport', { sport: model.sportName })
                    : t('home.daily.noneToday')}
                </Text>
                {/* Packs and Tournaments sit right below: point at them so Home never dead-ends. */}
                <Text style={styles.emptyHint}>{t('home.fallback.message')}</Text>
              </>
            ) : null}

            {model.kind === 'playable' ? (
              <AppButton
                title={t('home.daily.play')}
                onPress={() => onPlay(model.dailyChallengeId)}
                style={styles.action}
              />
            ) : null}
            {model.kind === 'played' ? (
              <AppButton
                title={t('home.daily.viewResult')}
                onPress={() => onViewResult(model.dailyChallengeId)}
                variant="secondary"
                style={styles.action}
              />
            ) : null}
          </>
        )}
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    dailyCard: {
      backgroundColor: theme.surface, borderRadius: 20, overflow: 'hidden',
      borderWidth: 1, borderColor: theme.border,
    },
    imageWrap: { position: 'absolute', top: 0, left: 0, right: 0 },
    body: { paddingHorizontal: spacing.md + 2, paddingBottom: spacing.md + 2 },
    title: {
      fontSize: 26, fontWeight: '800', color: theme.text, letterSpacing: 0.2,
      // Keeps the title crisp where it overlaps the faded artwork.
      textShadowColor: 'rgba(0,0,0,0.45)', textShadowOffset: { width: 0, height: 1 }, textShadowRadius: 6,
    },
    subtitle: { fontSize: 15, color: theme.textSecondary, marginTop: 2 },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.sm + 4 },
    metaText: { flexShrink: 1, fontSize: 15, fontWeight: '600', color: theme.text },
    pill: {
      alignSelf: 'flex-start', marginTop: spacing.sm,
      backgroundColor: theme.surfaceElevated, borderRadius: 8,
      paddingHorizontal: spacing.sm, paddingVertical: 3,
    },
    pillDone: { backgroundColor: theme.primary + '1f' },
    // Copy, not a backend value: keep its own casing.
    pillTextDone: { color: theme.primary, textTransform: 'none' },
    pillText: { fontSize: 12, fontWeight: '700', color: theme.textSecondary, textTransform: 'capitalize' },
    streak: { fontSize: 15, fontWeight: '700', color: theme.warning, marginTop: spacing.sm },
    empty: { fontSize: 14, lineHeight: 20, color: theme.text, marginTop: spacing.sm },
    emptyHint: { fontSize: 13, lineHeight: 18, color: theme.textMuted, marginTop: spacing.xs },
    loadingRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.md, minHeight: 52 },
    loadingText: { color: theme.textMuted, fontSize: 13, fontStyle: 'italic' },
    action: { marginTop: spacing.md },
  });
}
