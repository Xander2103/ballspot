import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { sportApi } from '../api/sportApi';
import { authApi } from '../api/authApi';
import { preferencesApi } from '../api/preferencesApi';
import { signOut } from '../app/signOut';
import type { Sport } from '../types/sport';
import type { RootStackParamList } from '../app/AppNavigator';
import { useI18n } from '../i18n';
import { getApiErrorMessage } from '../utils/apiError';
import { logoutLocally } from '../utils/session';

type Props = NativeStackScreenProps<RootStackParamList, 'SportSelection'>;

export function SportSelectionScreen({ route, navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const mode = route.params?.mode ?? 'onboarding';
  const currentId = route.params?.currentSportId ?? null;

  const [sports, setSports] = useState<Sport[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState(false);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const mounted = useRef(true);

  useEffect(() => () => { mounted.current = false; }, []);

  const loadSports = useCallback(async () => {
    setLoading(true);
    setLoadFailed(false);
    setError(null);
    try {
      // Active, selectable sports only (avoids inactive/admin data).
      const list = await sportApi.list();
      if (mounted.current) setSports(list);
    } catch {
      // In onboarding this screen is the only route on the stack and has no
      // back button, so a failed load must offer a way forward (Retry) and a
      // way out (Log out) — never an empty grid the user can only escape by
      // killing the app.
      if (mounted.current) setLoadFailed(true);
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, []);

  useEffect(() => { loadSports(); }, [loadSports]);

  async function leave() {
    if (mode === 'onboarding') {
      await logoutLocally({ apiLogout: () => authApi.logout(), clearLocal: signOut });
      navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
    } else {
      navigation.goBack();
    }
  }

  async function choose(sport: Sport) {
    if (savingId) return;
    // Coming soon: visible but not selectable — show a fun message instead.
    if (!sport.is_playable) {
      setError(t('home.sportSelection.comingSoonError', { sport: sport.name }));
      return;
    }
    setSavingId(sport.id);
    setError(null);
    try {
      await preferencesApi.update({ preferred_sport_id: sport.id });
      if (mode === 'onboarding') {
        navigation.reset({ index: 0, routes: [{ name: 'Home' }] });
      } else {
        navigation.goBack();
      }
    } catch (e: unknown) {
      setError(getApiErrorMessage(e, t('home.sportSelection.saveError')));
      setSavingId(null);
    }
  }

  return (
    <Screen scroll>
      <Text style={[styles.title, { color: theme.text }]}>{t('home.sportSelection.title')}</Text>
      <Text style={[styles.subtitle, { color: theme.textSecondary }]}>
        {t('home.sportSelection.subtitle')}
      </Text>

      {error && <Text style={[styles.error, { color: theme.danger }]}>{error}</Text>}

      {loading ? (
        <ActivityIndicator color={theme.primary} size="large" style={{ marginTop: spacing.xl }} />
      ) : loadFailed ? (
        <EmptyState
          icon="📡"
          title={t('home.sportSelection.loadError')}
          actions={[
            { label: t('common.buttons.retry'), onPress: () => { loadSports(); } },
            {
              label: mode === 'onboarding' ? t('common.buttons.logout') : t('common.buttons.back'),
              onPress: () => { leave(); },
            },
          ]}
        />
      ) : (
        <View style={styles.grid}>
          {sports.map((sport) => {
            const selected = sport.id === currentId;
            const comingSoon = sport.is_coming_soon;
            return (
              <TouchableOpacity
                key={sport.id}
                activeOpacity={0.8}
                onPress={() => choose(sport)}
                disabled={savingId !== null}
                style={[
                  styles.card,
                  {
                    backgroundColor: theme.surface,
                    borderColor: selected ? sport.primary_color : theme.border,
                    opacity: comingSoon ? 0.65 : 1,
                  },
                ]}
              >
                <View style={[styles.emojiWrap, { backgroundColor: sport.primary_color + '22' }]}>
                  <Text style={styles.emoji}>{sport.emoji}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <View style={styles.nameRow}>
                    <Text style={[styles.sportName, { color: theme.text }]}>{sport.name}</Text>
                    {comingSoon ? (
                      <View style={[styles.soonBadge, { backgroundColor: sport.primary_color + '2e', borderColor: sport.primary_color }]}>
                        <Text style={[styles.soonBadgeText, { color: sport.primary_color }]}>{t('home.sportSelection.soonBadge')}</Text>
                      </View>
                    ) : null}
                  </View>
                  <Text style={[styles.sportLabel, { color: theme.textMuted }]}>
                    {comingSoon ? t('home.sportSelection.comingSoon') : (sport.tagline ?? t('home.sportSelection.guessThe', { object: sport.object_name }))}
                  </Text>
                </View>
                {savingId === sport.id ? (
                  <ActivityIndicator color={sport.primary_color} />
                ) : selected ? (
                  <Text style={[styles.check, { color: sport.primary_color }]}>✓</Text>
                ) : null}
              </TouchableOpacity>
            );
          })}
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 28, fontWeight: '800', marginBottom: spacing.xs },
  subtitle: { fontSize: 15, marginBottom: spacing.lg, lineHeight: 21 },
  error: { fontSize: 14, marginBottom: spacing.md },
  grid: { gap: spacing.md },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: 16,
    borderWidth: 2,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  emojiWrap: {
    width: 52,
    height: 52,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.md,
  },
  emoji: { fontSize: 28 },
  nameRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  sportName: { fontSize: 18, fontWeight: '700' },
  sportLabel: { fontSize: 13, marginTop: 2 },
  soonBadge: { borderRadius: 6, borderWidth: 1, paddingHorizontal: 6, paddingVertical: 1 },
  soonBadgeText: { fontSize: 10, fontWeight: '800', letterSpacing: 0.5 },
  check: { fontSize: 22, fontWeight: '800', marginLeft: spacing.sm },
});

