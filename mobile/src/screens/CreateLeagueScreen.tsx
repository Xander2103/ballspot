import React, { useState } from 'react';
import { View, Text, StyleSheet, Alert } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { leagueApi } from '../api/leagueApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'CreateLeague'>;

function OptionRow({ label, options, value, onChange }: { label: string; options: number[]; value: number; onChange: (v: number) => void }) {
  return (
    <View style={styles.optionGroup}>
      <Text style={styles.optionLabel}>{label}</Text>
      <View style={styles.optionRow}>
        {options.map((opt) => (
          <AppButton
            key={opt}
            title={String(opt)}
            onPress={() => onChange(opt)}
            variant={value === opt ? 'primary' : 'secondary'}
            style={styles.optBtn}
          />
        ))}
      </View>
    </View>
  );
}

export function CreateLeagueScreen({ navigation }: Props) {
  const [name, setName] = useState('');
  const [durationDays, setDurationDays] = useState(3);
  const [roundsPerDay, setRoundsPerDay] = useState(1);
  const [loading, setLoading] = useState(false);

  async function handleCreate() {
    if (!name.trim()) { Alert.alert('Error', 'Tournament name is required'); return; }
    setLoading(true);
    try {
      const league = await leagueApi.create({ name: name.trim(), duration_days: durationDays, rounds_per_day: roundsPerDay });
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
      <AppInput label="Tournament Name" value={name} onChangeText={setName} placeholder="e.g. Friday Squad" />
      <OptionRow label="Duration" options={[1, 3, 7]} value={durationDays} onChange={setDurationDays} />
      <OptionRow label="Rounds per day" options={[1, 3]} value={roundsPerDay} onChange={setRoundsPerDay} />
      <Text style={styles.summary}>
        Total rounds: {durationDays * roundsPerDay}
      </Text>
      <AppButton title="Create Tournament" onPress={handleCreate} loading={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 22, fontWeight: '700', color: colors.text, marginBottom: spacing.lg },
  optionGroup: { marginBottom: spacing.md },
  optionLabel: { fontSize: 13, color: colors.textSecondary, marginBottom: spacing.xs, fontWeight: '600' },
  optionRow: { flexDirection: 'row', gap: spacing.sm },
  optBtn: { flex: 1 },
  summary: { textAlign: 'center', color: colors.primary, fontWeight: '700', marginBottom: spacing.lg, fontSize: 15 },
});
