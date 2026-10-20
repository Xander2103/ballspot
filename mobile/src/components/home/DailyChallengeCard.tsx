import React from 'react';
import { View, Text, StyleSheet, ActivityIndicator, useWindowDimensions } from 'react-native';
import { Image } from 'expo-image';
import Svg, { Path, Rect } from 'react-native-svg';
import { useTheme } from '../../theme/useTheme';
import { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { AppButton } from '../AppButton';
import { LineIcon } from '../LineIcon';
import { CardGradient } from './CardGradient';
import type { CountdownParts, DailyCardModel, NextDailyWhen } from '../../utils/homeModel';

const dailyImage = require('../../../assets/home/ballpicker_daily_rustig.png');

interface Props {
  model: DailyCardModel;
  /** Time left until the next daily, only when the backend confirmed it (see useCountdown). */
  countdown: CountdownParts | null;
  /** Which day the next daily lands on, for the empty card's single line. */
  nextWhen: NextDailyWhen;
  onPlay: (dailyChallengeId: number) => void;
  onViewResult: (dailyChallengeId: number) => void;
}

function todayDateFormatted(locale: string): string {
  // English keeps the day-first (en-GB) format the screen has always used.
  const dateLocale = locale === 'en' ? 'en-GB' : locale;
  return new Date().toLocaleDateString(dateLocale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function pad2(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

function nextDateFormatted(date: Date, locale: string): string {
  const dateLocale = locale === 'en' ? 'en-GB' : locale;
  return date.toLocaleDateString(dateLocale, { weekday: 'long', day: 'numeric', month: 'long' });
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
export function DailyChallengeCard({ model, countdown, nextWhen, onPlay, onViewResult }: Props) {
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

      {/* Quiet badge on the artwork, well above the title. Static text that
          changes once a minute: no blinking, no animation. */}
      {countdown ? (
        <View
          style={styles.countdown}
          testID="home-daily-countdown"
          accessible
          accessibilityRole="timer"
          accessibilityLabel={t('home.daily.countdownA11y', { hours: countdown.hours, minutes: countdown.minutes })}
        >
          <LineIcon name="clock" color={theme.primary} size={13} strokeWidth={2.2} />
          <Text style={styles.countdownLabel} maxFontSizeMultiplier={1.3}>{t('home.daily.countdown')}</Text>
          <Text style={styles.countdownValue} maxFontSizeMultiplier={1.3}>
            {t('home.daily.countdownValue', { hours: pad2(countdown.hours), minutes: pad2(countdown.minutes) })}
          </Text>
        </View>
      ) : null}

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

            {/* One line; the countdown badge (when confirmed) carries the exact time. */}
            {model.kind === 'none' ? (
              <Text style={styles.empty}>
                {model.isOtherSport
                  ? t('home.daily.noneForSport', { sport: model.sportName })
                  : nextWhen.when === 'laterToday'
                    ? t('home.daily.nextLaterToday')
                    : nextWhen.when === 'later'
                      ? t('home.daily.nextOn', { date: nextDateFormatted(nextWhen.date, locale) })
                      : t('home.daily.nextTomorrow')}
              </Text>
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
    empty: { fontSize: 14, lineHeight: 20, color: theme.textSecondary, marginTop: spacing.sm },
    countdown: {
      position: 'absolute', top: spacing.sm + 4, left: spacing.sm + 4,
      flexDirection: 'row', alignItems: 'center', gap: 6,
      paddingHorizontal: 10, paddingVertical: 5, borderRadius: 999,
      // Dark, slightly see-through green: readable on the bright artwork
      // without hiding it. Hex alpha on the theme tokens (cc ≈ 80%, 59 ≈ 35%).
      backgroundColor: theme.background + 'cc',
      borderWidth: 1, borderColor: theme.primary + '59',
    },
    countdownLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary },
    countdownValue: { fontSize: 12, fontWeight: '700', color: theme.text, fontVariant: ['tabular-nums'] },
    loadingRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.md, minHeight: 52 },
    loadingText: { color: theme.textMuted, fontSize: 13, fontStyle: 'italic' },
    action: { marginTop: spacing.md },
  });
}
