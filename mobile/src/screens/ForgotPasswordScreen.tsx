import React, { useState } from 'react';
import { Text, StyleSheet, View } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage, isNetworkError } from '../utils/apiError';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'ForgotPassword'>;

export function ForgotPasswordScreen({ navigation }: Props) {
  const { t } = useI18n();
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit() {
    if (loading) return;
    const trimmed = email.trim();
    if (!trimmed) {
      setError(t('auth.forgotPassword.emailRequired'));
      return;
    }
    setLoading(true);
    setError('');
    try {
      // The backend answers the same generic success whether or not the
      // address exists (no enumeration). Only real request failures — offline,
      // rate limited, invalid address, server down — are shown.
      await authApi.forgotPassword({ email: trimmed });
      setSent(true);
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status;
      if (isNetworkError(e) || status === 429 || status === 422 || (status ?? 0) >= 500) {
        setError(getApiErrorMessage(e, t('auth.forgotPassword.failed')));
      } else {
        setSent(true);
      }
    } finally {
      setLoading(false);
    }
  }

  if (sent) {
    return (
      <Screen scroll padding>
        <View style={styles.confirmBox}>
          <Text style={styles.confirmIcon}>📧</Text>
          <Text style={styles.title}>{t('auth.verification.checkEmail')}</Text>
          <Text style={styles.body}>
            {t('auth.forgotPassword.sentPrefix')}{'\n'}
            <Text style={styles.email}>{email.trim()}</Text>,{'\n'}
            {t('auth.forgotPassword.sentSuffix')}
          </Text>
          <Text style={styles.hint}>
            {t('auth.forgotPassword.sentHint')}
          </Text>
          <AppButton
            title={t('auth.forgotPassword.haveLink')}
            onPress={() => navigation.navigate('ResetPassword', { email: email.trim() })}
            style={styles.btn}
          />
          <AppButton title={t('auth.backToLogin')} variant="secondary" onPress={() => navigation.navigate('Login')} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('auth.forgotPassword.title')}</Text>
      <Text style={styles.body}>
        {t('auth.forgotPassword.intro')}
      </Text>
      {error ? <Text style={styles.formError}>{error}</Text> : null}
      <AppInput
        label={t('common.labels.email')}
        value={email}
        onChangeText={(t) => { setEmail(t); setError(''); }}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        returnKeyType="send"
        onSubmitEditing={handleSubmit}
      />
      <AppButton title={t('auth.forgotPassword.submit')} onPress={handleSubmit} loading={loading} style={styles.btn} />
      <AppButton title={t('auth.backToLogin')} variant="secondary" onPress={() => navigation.goBack()} disabled={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.md },
  body: { fontSize: 15, color: colors.textSecondary, marginBottom: spacing.lg, lineHeight: 22 },
  hint: { fontSize: 13, color: colors.textMuted, marginBottom: spacing.lg, textAlign: 'center', lineHeight: 19 },
  formError: { color: colors.error, fontSize: 14, marginBottom: spacing.md },
  btn: { marginBottom: spacing.sm },
  confirmBox: { alignItems: 'center', paddingTop: spacing.xl },
  confirmIcon: { fontSize: 48, marginBottom: spacing.md },
  email: { color: colors.text, fontWeight: '700' },
});
