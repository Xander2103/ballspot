import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, Alert, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { getTournamentErrorMessage, isTournamentsUnavailable, type TournamentAvailability } from '../utils/tournamentErrors';
import type { Sport } from '../types/sport';
import { useI18n, type TranslateParams } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'CreateLeague'>;

/**
 * Fixed tournament lengths (v1.9.0). One photo per day, so days === photos.
 * "1 month" is 30 days server-side. Must match the backend allow-list
 * (config ballspot.tournaments.allowed_duration_days).
 */
const DURATION_DAYS = [7, 14, 30] as const;

type T = (key: string, params?: TranslateParams) => string;

/** "7 days" / "14 days" / "1 month" — resolved at render so it follows the active language. */
function durationLabel(t: T, days: number): string {
  return days === 30 ? t('tournaments.create.oneMonth') : t('common.time.days', { count: days });
}

function DurationSelector({ value, onChange, styles }: { value: number; onChange: (v: number) => void; styles: Styles }) {
  const { t } = useI18n();
  return (
    <View style={styles.optionGroup}>
      <Text style={styles.optionLabel}>{t('tournaments.create.duration')}</Text>
      <View style={styles.optionRow}>
        {DURATION_DAYS.map((days) => {
          const selected = value === days;
          const label = durationLabel(t, days);
          const photos = t('tournaments.create.photos', { count: days });
          return (
            <Pressable
              key={days}
              onPress={() => onChange(days)}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              accessibilityLabel={t('tournaments.create.durationOption', { label, photos })}
              style={[styles.optCard, selected && styles.optCardSelected]}
            >
              <Text style={[styles.optCardTitle, selected && styles.optCardTitleSelected]}>{label}</Text>
              <Text style={[styles.optCardSub, selected && styles.optCardSubSelected]}>{photos}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function CreateLeagueScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [name, setName] = useState('');
  const [durationDays, setDurationDays] = useState(7);
  const [loading, setLoading] = useState(false);
  const [sport, setSport] = useState<Sport | null>(null);
  // null = unknown (request failed/in flight): never block the user on a
  // failed probe — the create call answers with the same friendly code.
  const [availability, setAvailability] = useState<TournamentAvailability | null>(null);

  useEffect(() => {
    authApi.me().then((me) => setSport(me.preferred_sport ?? null)).catch(() => {});
  }, []);

  // Re-check when the length changes: a 7-day tournament may fit where a
  // 30-day one does not.
  useEffect(() => {
    let cancelled = false;
    leagueApi.availability({ duration_days: durationDays, sport: sport?.slug ?? null })
      .then((res) => { if (!cancelled) setAvailability(res); })
      .catch(() => { if (!cancelled) setAvailability(null); });
    return () => { cancelled = true; };
  }, [durationDays, sport?.slug]);

  const unavailable = availability?.available === false;

  async function handleCreate() {
    if (!name.trim()) { Alert.alert(t('tournaments.alerts.error'), t('tournaments.create.errors.nameRequired')); return; }
    setLoading(true);
    try {
      const league = await leagueApi.create({
        name: name.trim(),
        duration_days: durationDays,
        rounds_per_day: 1, // v1.8.8: one photo per day (server enforces this too)
        sport_id: sport?.id ?? null,
      });
      navigation.replace('LeagueDetail', { leagueId: league.id, leagueName: league.name });
    } catch (e: unknown) {
      if (isTournamentsUnavailable(e)) {
        // Friendly, never the raw 422 — and back to the list, not stuck here.
        setAvailability({ available: false, required: e.required ?? durationDays, available_challenges: e.available ?? 0, message: null });
        Alert.alert(t('tournaments.unavailable.title'), t('tournaments.unavailable.body'), [
          { text: t('common.buttons.ok'), onPress: () => navigation.goBack() },
        ]);
        return;
      }
      Alert.alert(t('tournaments.create.errors.title'), getTournamentErrorMessage(e, t('tournaments.create.errors.failed')));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('tournaments.create.title')}</Text>

      <View style={styles.sportBanner}>
        <Text style={styles.sportBannerLabel}>{t('tournaments.create.sport')}</Text>
        <Text style={styles.sportBannerValue}>
          {sport ? `${sport.emoji} ${sport.name}` : t('tournaments.create.defaultSport')}
        </Text>
      </View>

      <AppInput
        label={t('tournaments.create.nameLabel')}
        value={name}
        onChangeText={setName}
        placeholder={t('tournaments.create.namePlaceholder')}
      />
      <DurationSelector value={durationDays} onChange={setDurationDays} styles={styles} />
      <Text style={styles.helperText}>{t('tournaments.create.helper')}</Text>
      <Text style={styles.summary}>
        {t('tournaments.create.summary', {
          label: durationLabel(t, durationDays),
          photos: t('tournaments.create.photos', { count: durationDays }),
        })}
      </Text>
      {unavailable ? (
        <View style={styles.unavailableCard} accessibilityRole="alert">
          <Text style={styles.unavailableTitle}>{t('tournaments.unavailable.title')}</Text>
          <Text style={styles.unavailableBody}>{t('tournaments.unavailable.body')}</Text>
        </View>
      ) : null}
      <AppButton title={t('tournaments.create.submit')} onPress={handleCreate} loading={loading} disabled={unavailable} />
      <Text style={styles.freeNote}>{t('tournaments.create.limitsNote')}</Text>
      <Text style={styles.comingSoon}>{t('tournaments.create.changeSportNote')}</Text>
    </Screen>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    title: { fontSize: 22, fontWeight: '700', color: theme.text, marginBottom: spacing.lg },
    sportBanner: {
      flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
      backgroundColor: theme.surfaceElevated, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm, marginBottom: spacing.lg,
    },
    sportBannerLabel: { fontSize: 12, color: theme.textSecondary, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 1 },
    sportBannerValue: { fontSize: 16, color: theme.text, fontWeight: '700' },
    optionGroup: { marginBottom: spacing.md },
    optionLabel: { fontSize: 13, color: theme.textSecondary, marginBottom: spacing.xs, fontWeight: '600' },
    optionRow: { flexDirection: 'row', gap: spacing.sm },
    optCard: {
      flex: 1, alignItems: 'center', paddingVertical: spacing.sm, paddingHorizontal: spacing.xs,
      borderRadius: 12, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
    },
    optCardSelected: { borderColor: theme.primary, backgroundColor: theme.primary },
    optCardTitle: { fontSize: 15, fontWeight: '700', color: theme.text },
    optCardTitleSelected: { color: theme.onPrimary },
    optCardSub: { fontSize: 12, color: theme.textSecondary, marginTop: 2 },
    optCardSubSelected: { color: theme.onPrimary, opacity: 0.85 },
    helperText: { color: theme.textSecondary, fontSize: 12, marginBottom: 4 },
    summary: { textAlign: 'center', color: theme.primary, fontWeight: '700', marginVertical: spacing.lg, fontSize: 15 },
    unavailableCard: {
      backgroundColor: theme.surfaceElevated, borderRadius: 12, borderWidth: 1, borderColor: theme.border,
      paddingHorizontal: spacing.md, paddingVertical: spacing.sm, marginBottom: spacing.md,
    },
    unavailableTitle: { fontSize: 14, fontWeight: '700', color: theme.text, marginBottom: 2 },
    unavailableBody: { fontSize: 13, color: theme.textSecondary, lineHeight: 18 },
    freeNote: { textAlign: 'center', color: theme.textSecondary, fontSize: 12, marginTop: spacing.lg },
    comingSoon: { textAlign: 'center', color: theme.textMuted, fontSize: 12, marginTop: 4, fontStyle: 'italic' },
  });
}
