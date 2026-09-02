import React, { useState } from 'react';
import { Text, StyleSheet, Alert } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { leagueApi } from '../api/leagueApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage } from '../utils/apiError';

type Props = NativeStackScreenProps<RootStackParamList, 'JoinLeague'>;

export function JoinLeagueScreen({ navigation }: Props) {
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleJoin() {
    const trimmed = code.trim().toUpperCase();
    if (trimmed.length !== 6) { Alert.alert('Error', 'Join code must be 6 characters'); return; }
    setLoading(true);
    try {
      const league = await leagueApi.join(trimmed);
      navigation.replace('LeagueDetail', { leagueId: league.id, leagueName: league.name });
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status;
      Alert.alert(
        'Could not join tournament',
        status === 404
          ? 'No tournament found for that code. Check the code and try again.'
          : getApiErrorMessage(e, 'Could not join this tournament. Please try again.'),
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Join a League</Text>
      <Text style={styles.sub}>Ask the league creator for their 6-character join code.</Text>
      <AppInput
        label="Join Code"
        value={code}
        onChangeText={setCode}
        autoCapitalize="characters"
        maxLength={6}
        placeholder="ABC123"
        style={styles.codeInput}
      />
      <AppButton title="Join League" onPress={handleJoin} loading={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 22, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  sub: { color: colors.textSecondary, marginBottom: spacing.lg },
  codeInput: { fontSize: 24, letterSpacing: 8, textAlign: 'center' } as any,
});
