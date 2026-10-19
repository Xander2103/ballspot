import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { clubApi } from '../api/clubApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import { formatClubCode, normaliseClubCode, parseClubCode, CLUB_CODE_LENGTH } from '../utils/clubCode';
import { getClubErrorMessage } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'JoinClub'>;

/**
 * Join a club with an invite code (typed, pasted or scanned). A separate flow
 * from My Clubs search. Success — and "already a member" — open the club.
 */
export function JoinClubScreen({ navigation, route }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [code, setCode] = useState('');
  const [joining, setJoining] = useState(false);
  const [error, setError] = useState('');
  // Synchronous in-flight guard: a double tap (or a scan + tap) joins once.
  const inFlight = useRef(false);

  const join = useCallback(async (raw: string) => {
    if (inFlight.current) return;
    const normalised = parseClubCode(raw);
    if (!normalised) {
      setError(t('clubs.join.incomplete'));
      return;
    }
    inFlight.current = true;
    setJoining(true);
    setError('');
    try {
      const res = await clubApi.join(normalised);
      navigation.replace('ClubDetail', {
        clubId: res.data.id,
        name: res.data.name,
        notice: res.joined ? 'joined' : 'alreadyMember',
      });
    } catch (e: unknown) {
      setError(getClubErrorMessage(e, t('clubs.join.failed')));
    } finally {
      inFlight.current = false;
      setJoining(false);
    }
  }, [navigation, t]);

  // A code handed back by ScanClubCodeScreen: fill in and join right away.
  const scanned = route.params?.scannedCode;
  useEffect(() => {
    if (!scanned) return;
    setCode(formatClubCode(scanned));
    navigation.setParams({ scannedCode: undefined });
    void join(scanned);
  }, [scanned, navigation, join]);

  const complete = normaliseClubCode(code).length === CLUB_CODE_LENGTH;

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('clubs.join.title')}</Text>
      <Text style={styles.sub}>{t('clubs.join.subtitle')}</Text>
      <AppInput
        label={t('clubs.join.codeLabel')}
        value={code}
        onChangeText={(text) => { setCode(formatClubCode(text)); if (error) setError(''); }}
        autoCapitalize="characters"
        autoCorrect={false}
        autoComplete="off"
        // No maxLength: a pasted "ballpicker:club:…" payload must arrive whole;
        // formatClubCode caps the value at 8 characters + the dash.
        placeholder={t('clubs.join.codePlaceholder')}
        style={styles.codeInput}
        returnKeyType="go"
        onSubmitEditing={() => { void join(code); }}
        error={error || undefined}
        accessibilityLabel={t('clubs.join.codeLabel')}
      />
      <AppButton title={t('clubs.join.submit')} onPress={() => { void join(code); }} loading={joining} disabled={!complete} />

      <View style={styles.orRow}>
        <View style={styles.orLine} />
        <Text style={styles.orText}>{t('clubs.join.or')}</Text>
        <View style={styles.orLine} />
      </View>

      <AppButton title={`📷 ${t('clubs.join.scan')}`} onPress={() => navigation.navigate('ScanClubCode')} variant="secondary" disabled={joining} />

      <Text style={styles.note}>{t('clubs.join.privacyNote')}</Text>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    title: { fontSize: 22, fontWeight: '700', color: theme.text, marginBottom: spacing.sm },
    sub: { color: theme.textSecondary, marginBottom: spacing.lg, lineHeight: 20 },
    codeInput: { fontSize: 24, letterSpacing: 4, textAlign: 'center', fontWeight: '700' },
    orRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginVertical: spacing.lg },
    orLine: { flex: 1, height: 1, backgroundColor: theme.border },
    orText: { fontSize: 12, color: theme.textMuted, textTransform: 'uppercase', letterSpacing: 1 },
    note: { fontSize: 12, color: theme.textMuted, marginTop: spacing.lg, lineHeight: 18, textAlign: 'center' },
  });
}
