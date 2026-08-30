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
import type { Sport } from '../types/sport';

type Props = NativeStackScreenProps<RootStackParamList, 'CreateLeague'>;

/**
 * Fixed tournament lengths (v1.9.0). One photo per day, so days === photos.
 * "1 month" is 30 days server-side. Must match the backend allow-list
 * (config ballspot.tournaments.allowed_duration_days).
 */
const DURATION_OPTIONS: { days: number; label: string }[] = [
  { days: 7, label: '7 days' },
  { days: 14, label: '14 days' },
  { days: 30, label: '1 month' },
];

function DurationSelector({ value, onChange, styles }: { value: number; onChange: (v: number) => void; styles: Styles }) {
  return (
    <View style={styles.optionGroup}>
      <Text style={styles.optionLabel}>Duration</Text>
      <View style={styles.optionRow}>
        {DURATION_OPTIONS.map((opt) => {
          const selected = value === opt.days;
          return (
            <Pressable
              key={opt.days}
              onPress={() => onChange(opt.days)}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              accessibilityLabel={`${opt.label}, ${opt.days} photos`}
              style={[styles.optCard, selected && styles.optCardSelected]}
            >
              <Text style={[styles.optCardTitle, selected && styles.optCardTitleSelected]}>{opt.label}</Text>
              <Text style={[styles.optCardSub, selected && styles.optCardSubSelected]}>{opt.days} photos</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function CreateLeagueScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [name, setName] = useState('');
  const [durationDays, setDurationDays] = useState(7);
  const [loading, setLoading] = useState(false);
  const [sport, setSport] = useState<Sport | null>(null);

  useEffect(() => {
    authApi.me().then((me) => setSport(me.preferred_sport ?? null)).catch(() => {});
  }, []);

  async function handleCreate() {
    if (!name.trim()) { Alert.alert('Error', 'Tournament name is required'); return; }
    setLoading(true);
    try {
      const league = await leagueApi.create({
        name: name.trim(),
        duration_days: durationDays,
        rounds_per_day: 1, // v1.8.8: one photo per day (server enforces this too)
        sport_id: sport?.id ?? null,
      });
      navigation.replace('LeagueDetail', { leagueId: league.id, leagueName: league.name });
    } catch (e: any) {
      Alert.alert('Error', e?.message || 'Failed to create league');
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>New Tournament</Text>

      <View style={styles.sportBanner}>
        <Text style={styles.sportBannerLabel}>Sport</Text>
        <Text style={styles.sportBannerValue}>
          {sport ? `${sport.emoji} ${sport.name}` : '⚽ Football'}
        </Text>
      </View>

      <AppInput label="Tournament Name" value={name} onChangeText={setName} placeholder="e.g. Friday Squad" />
      <DurationSelector value={durationDays} onChange={setDurationDays} styles={styles} />
      <Text style={styles.helperText}>Players get 1 photo per day.</Text>
      <Text style={styles.summary}>
        {`${DURATION_OPTIONS.find((o) => o.days === durationDays)?.label ?? `${durationDays} days`} · ${durationDays} photos`}
      </Text>
      <AppButton title="Create Tournament" onPress={handleCreate} loading={loading} />
      <Text style={styles.freeNote}>You can host 1 tournament and be in up to 2 at the same time. Up to 8 players each.</Text>
      <Text style={styles.comingSoon}>You can change your sport in your profile.</Text>
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
    freeNote: { textAlign: 'center', color: theme.textSecondary, fontSize: 12, marginTop: spacing.lg },
    comingSoon: { textAlign: 'center', color: theme.textMuted, fontSize: 12, marginTop: 4, fontStyle: 'italic' },
  });
}
