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
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'JoinLeague'>;

export function JoinLeagueScreen({ navigation }: Props) {
  const { t } = useI18n();
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleJoin() {
    const trimmed = code.trim().toUpperCase();
    if (trimmed.length !== 6) { Alert.alert(t('tournaments.alerts.error'), t('tournaments.join.errors.codeLength')); return; }
    setLoading(true);
    try {
      const league = await leagueApi.join(trimmed);
      navigation.replace('LeagueDetail', { leagueId: league.id, leagueName: league.name });
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status;
      Alert.alert(
        t('tournaments.join.errors.title'),
        status === 404
          ? t('tournaments.join.errors.notFound')
          : getApiErrorMessage(e, t('tournaments.join.errors.failed')),
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('tournaments.join.title')}</Text>
      <Text style={styles.sub}>{t('tournaments.join.subtitle')}</Text>
      <AppInput
        label={t('tournaments.join.codeLabel')}
        value={code}
        onChangeText={setCode}
        autoCapitalize="characters"
        maxLength={6}
        placeholder={t('tournaments.join.codePlaceholder')}
        style={styles.codeInput}
      />
      <AppButton title={t('tournaments.join.submit')} onPress={handleJoin} loading={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 22, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  sub: { color: colors.textSecondary, marginBottom: spacing.lg },
  codeInput: { fontSize: 24, letterSpacing: 8, textAlign: 'center' } as any,
});
