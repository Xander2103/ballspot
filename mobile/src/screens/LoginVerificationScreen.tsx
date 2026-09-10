import React, { useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, TextInput, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { completeLogin } from '../app/authFlow';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { mapAuthError } from '../utils/authErrors';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'LoginVerification'>;

const RESEND_COOLDOWN = 60;

export function LoginVerificationScreen({ route, navigation }: Props) {
  const { verificationId, email } = route.params;
  const { theme, setTheme } = useTheme();
  const styles = createStyles(theme);
  const { t } = useI18n();

  const [code, setCode] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [cooldown, setCooldown] = useState(RESEND_COOLDOWN);

  const inputRef = useRef<TextInput>(null);

  // Autofocus the code field.
  useEffect(() => {
    const timer = setTimeout(() => inputRef.current?.focus(), 300);
    return () => clearTimeout(timer);
  }, []);

  // Resend cooldown ticker.
  useEffect(() => {
    if (cooldown <= 0) return;
    const id = setInterval(() => setCooldown((c) => (c > 0 ? c - 1 : 0)), 1000);
    return () => clearInterval(id);
  }, [cooldown]);

  async function handleVerify() {
    if (code.length !== 6) {
      setError(t('errors.validation.codeRequired'));
      return;
    }
    setVerifying(true);
    setError('');
    setNotice('');
    try {
      const { token } = await authApi.verifyLoginCode({ verification_id: verificationId, code });
      const target = await completeLogin(token, setTheme);
      navigation.reset({ index: 0, routes: [{ name: target }] });
    } catch (e: unknown) {
      // wrong / expired / locked / session gone — each gets its own sentence.
      const info = mapAuthError(e, t('errors.verification.invalidOrExpired'));
      setError(info.message);
      setCode('');
      setVerifying(false);
      if (info.code === 'two_factor_locked') {
        setCooldown(0); // the fix is a resend — do not make them wait for it
      } else if (info.code === 'two_factor_code_expired' || info.code === 'two_factor_session_invalid') {
        setTimeout(() => navigation.goBack(), 1500); // must log in again
      }
    }
  }

  async function handleResend() {
    if (cooldown > 0 || resending) return;
    setResending(true);
    setError('');
    setNotice('');
    try {
      await authApi.resendLoginCode({ verification_id: verificationId });
      setNotice(t('auth.loginVerification.resent'));
      setCooldown(RESEND_COOLDOWN);
    } catch (e: unknown) {
      // Expired/invalid session → the user must start over.
      const info = mapAuthError(e, t('auth.verification.resendFailed'));
      setError(info.message);
      if (info.code === 'two_factor_session_invalid' || info.code === 'two_factor_code_expired' || /login again/i.test(info.message)) {
        setTimeout(() => navigation.goBack(), 1200);
      }
    } finally {
      setResending(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('auth.verification.checkEmail')}</Text>
      <Text style={styles.subtitle}>
        {email ? t('auth.loginVerification.subtitleTo', { email }) : t('auth.loginVerification.subtitle')}
      </Text>

      <TextInput
        ref={inputRef}
        style={styles.codeInput}
        value={code}
        onChangeText={(t) => { setCode(t.replace(/[^0-9]/g, '').slice(0, 6)); setError(''); }}
        keyboardType="number-pad"
        maxLength={6}
        placeholder="______"
        placeholderTextColor={theme.textMuted}
        textContentType="oneTimeCode"
        autoComplete="sms-otp"
        returnKeyType="done"
        onSubmitEditing={handleVerify}
      />

      {error ? <Text style={styles.error}>{error}</Text> : null}
      {notice ? <Text style={styles.notice}>{notice}</Text> : null}

      <AppButton
        title={t('auth.loginVerification.verify')}
        onPress={handleVerify}
        loading={verifying}
        disabled={code.length !== 6 || verifying}
        style={styles.verifyBtn}
      />

      <Pressable onPress={handleResend} disabled={cooldown > 0 || resending} hitSlop={8} style={styles.resend}>
        <Text style={[styles.resendText, (cooldown > 0 || resending) && styles.resendDisabled]}>
          {cooldown > 0 ? t('auth.verification.resendIn', { seconds: cooldown }) : resending ? t('common.states.sending') : t('auth.verification.resend')}
        </Text>
      </Pressable>

      <Pressable onPress={() => navigation.goBack()} hitSlop={8} style={styles.back}>
        <Text style={styles.backText}>{t('auth.backToLogin')}</Text>
      </Pressable>
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    title: { fontSize: 26, fontWeight: '800', color: theme.text, marginTop: spacing.xl, marginBottom: spacing.xs },
    subtitle: { fontSize: 15, color: theme.textSecondary, marginBottom: spacing.xl, lineHeight: 21 },
    codeInput: {
      height: 64,
      borderRadius: 12,
      backgroundColor: theme.surfaceElevated,
      borderWidth: 1,
      borderColor: theme.border,
      color: theme.text,
      fontSize: 30,
      fontWeight: '800',
      letterSpacing: 12,
      textAlign: 'center',
    },
    error: { color: theme.danger, fontSize: 14, marginTop: spacing.md },
    notice: { color: theme.success, fontSize: 14, marginTop: spacing.md },
    verifyBtn: { marginTop: spacing.lg },
    resend: { alignSelf: 'center', paddingVertical: spacing.md, marginTop: spacing.sm },
    resendText: { color: theme.accent, fontSize: 15, fontWeight: '700' },
    resendDisabled: { color: theme.textMuted },
    back: { alignSelf: 'center', paddingVertical: spacing.sm },
    backText: { color: theme.textSecondary, fontSize: 14, fontWeight: '600' },
  });
}
