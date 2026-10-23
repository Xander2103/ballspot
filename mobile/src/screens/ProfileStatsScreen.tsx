import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { SegmentedControl } from '../components/SegmentedControl';
import { StatList } from '../components/StatList';
import { ProfileHistoryCard } from '../components/ProfileHistoryCard';
import { EmptyState } from '../components/EmptyState';
import { authApi } from '../api/authApi';
import { badgeApi } from '../api/badgeApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import { statsSection, STATS_SECTIONS, type StatsSection } from '../utils/profileModel';
import type { ProfileStats } from '../types/auth';
import type { TournamentFinish } from '../types/badge';

type Props = NativeStackScreenProps<RootStackParamList, 'ProfileStats'>;

/**
 * "Your stats": the tournament statistics, Daily Challenge statistics and
 * tournament history that used to be collapsible sections on Profile. A
 * Profile row deep-links to its segment; the data is re-fetched here so the
 * numbers are never stale.
 */
export function ProfileStatsScreen({ route }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [section, setSection] = useState<StatsSection>(statsSection(route.params?.section));
  const [stats, setStats] = useState<ProfileStats | null>(null);
  const [history, setHistory] = useState<TournamentFinish[]>([]);
  const [loading, setLoading] = useState(true);
  const [statsFailed, setStatsFailed] = useState(false);
  const [historyFailed, setHistoryFailed] = useState(false);

  const load = useCallback(async () => {
    // allSettled: a failing history must not hide the stats, and vice versa.
    const [statsRes, finishRes] = await Promise.allSettled([authApi.stats(), badgeApi.finishes()]);
    if (statsRes.status === 'fulfilled') setStats(statsRes.value);
    if (finishRes.status === 'fulfilled') setHistory(finishRes.value);
    setStatsFailed(statsRes.status === 'rejected');
    setHistoryFailed(finishRes.status === 'rejected');
  }, []);

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, [load]);

  const retry = () => {
    setLoading(true);
    load().finally(() => setLoading(false));
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  const labels: Record<StatsSection, string> = {
    overall: t('profile.statsScreen.overall'),
    daily: t('profile.statsScreen.daily'),
    history: t('profile.statsScreen.history'),
  };

  function renderStats(section: 'overall' | 'daily') {
    if (!stats) {
      return (
        <EmptyState
          compact
          message={statsFailed ? t('profile.screen.statsError') : t('common.states.couldNotLoad')}
          actions={[{ label: t('common.buttons.retry'), onPress: retry }]}
        />
      );
    }
    if (section === 'overall') {
      return (
        <StatList
          items={[
            { label: t('profile.stats.tournaments'), value: stats.tournaments_count },
            { label: t('profile.stats.completed'), value: stats.completed_tournaments_count },
            { label: t('profile.stats.guesses'), value: stats.guesses_count },
            { label: t('profile.stats.totalScore'), value: stats.total_score, highlight: true },
            { label: t('profile.stats.averageScore'), value: stats.average_score },
          ]}
        />
      );
    }
    return (
      <>
        {stats.current_daily_streak > 0 ? (
          <Text style={styles.streak} maxFontSizeMultiplier={1.4}>
            {t('profile.screen.streakSummary', { days: stats.current_daily_streak })}
          </Text>
        ) : null}
        <StatList
          items={[
            {
              label: t('profile.stats.currentStreak'),
              value: t('common.time.days', { count: stats.current_daily_streak }),
              detail: t('profile.stats.bestDetail', { best: stats.best_daily_streak }),
            },
            { label: t('profile.stats.played'), value: stats.daily_challenges_played },
            { label: t('profile.stats.averageScore'), value: stats.average_daily_score },
            { label: t('profile.stats.bestScore'), value: stats.best_daily_score, highlight: true },
          ]}
        />
      </>
    );
  }

  function renderHistory() {
    if (history.length > 0) return <ProfileHistoryCard finishes={history} flat />;
    if (historyFailed) {
      return (
        <EmptyState
          compact
          message={t('profile.screen.historyError')}
          actions={[{ label: t('common.buttons.retry'), onPress: retry }]}
        />
      );
    }
    return <EmptyState compact message={t('profile.screen.historyEmpty')} />;
  }

  return (
    <Screen scroll padding={false} keyboardSafe={false}>
      <View style={styles.content}>
        <SegmentedControl
          segments={STATS_SECTIONS.map((key) => ({ key, label: labels[key], testID: `stats-tab-${key}` }))}
          active={section}
          onChange={setSection}
        />
        <View style={styles.card} testID={`stats-section-${section}`}>
          {section === 'history' ? renderHistory() : renderStats(section)}
        </View>
      </View>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' },
    content: { paddingHorizontal: spacing.md, paddingTop: spacing.md, paddingBottom: spacing.xl, gap: spacing.md },
    card: {
      backgroundColor: theme.surface, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.xs,
    },
    streak: { fontSize: 15, fontWeight: '800', color: theme.text, paddingTop: spacing.sm + 2, paddingBottom: spacing.xs },
  });
}
