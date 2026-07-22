import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ActivityIndicator, Alert } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Screen } from '../components/Screen';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { sportApi } from '../api/sportApi';
import { preferencesApi } from '../api/preferencesApi';
import type { Sport } from '../types/sport';
import type { RootStackParamList } from '../app/AppNavigator';

type Props = NativeStackScreenProps<RootStackParamList, 'SportSelection'>;

export function SportSelectionScreen({ route, navigation }: Props) {
  const { theme } = useTheme();
  const mode = route.params?.mode ?? 'onboarding';
  const currentId = route.params?.currentSportId ?? null;

  const [sports, setSports] = useState<Sport[]>([]);
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    sportApi
      .list(true) // include inactive so we can show "Coming soon"
      .then((list) => {
        if (active) setSports(list);
      })
      .catch(() => {
        if (active) setError('Could not load sports. Please try again.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  async function choose(sport: Sport) {
    if (!sport.is_active || savingId) return;
    setSavingId(sport.id);
    setError(null);
    try {
      await preferencesApi.update({ preferred_sport_id: sport.id });
      if (mode === 'onboarding') {
        navigation.reset({ index: 0, routes: [{ name: 'Home' }] });
      } else {
        navigation.goBack();
      }
    } catch (e: any) {
      setError(e?.message ?? 'Could not save your choice. Please try again.');
      setSavingId(null);
    }
  }

  return (
    <Screen scroll>
      <Text style={[styles.title, { color: theme.text }]}>Pick your sport</Text>
      <Text style={[styles.subtitle, { color: theme.textSecondary }]}>
        Choose what you want to play first. You can change this later in your profile.
      </Text>

      {error && <Text style={[styles.error, { color: theme.danger }]}>{error}</Text>}

      {loading ? (
        <ActivityIndicator color={theme.primary} size="large" style={{ marginTop: spacing.xl }} />
      ) : (
        <View style={styles.grid}>
          {sports.map((sport) => {
            const selected = sport.id === currentId;
            const disabled = !sport.is_active;
            return (
              <TouchableOpacity
                key={sport.id}
                activeOpacity={disabled ? 1 : 0.8}
                onPress={() => choose(sport)}
                disabled={disabled || savingId !== null}
                style={[
                  styles.card,
                  {
                    backgroundColor: theme.surface,
                    borderColor: selected ? sport.primary_color : theme.border,
                    opacity: disabled ? 0.55 : 1,
                  },
                ]}
              >
                <View style={[styles.emojiWrap, { backgroundColor: sport.primary_color + '22' }]}>
                  <Text style={styles.emoji}>{sport.emoji}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.sportName, { color: theme.text }]}>{sport.name}</Text>
                  <Text style={[styles.sportLabel, { color: theme.textMuted }]}>
                    {disabled ? 'Coming soon' : `Guess the ${sport.object_name}`}
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
  sportName: { fontSize: 18, fontWeight: '700' },
  sportLabel: { fontSize: 13, marginTop: 2 },
  check: { fontSize: 22, fontWeight: '800', marginLeft: spacing.sm },
});
