import React, { useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, TextInput, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { applyProfileAndRoute } from '../app/authFlow';
import { tokenStorage } from '../storage/tokenStorage';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'EmailVerification'>;

const RESEND_COOLDOWN = 60;

/**
 * Registration step 2 — confirm the email address with the 6-digit code.
 * The auth token is already stored; verifying unlocks full app access.
 */
export function EmailVerificationScreen({ route, navigation }: Props) {
  const { email } = route.params ?? {};
  const { theme, setTheme } = useTheme();
  const styles = createStyles(theme);

  const [code, setCode] = useState('');
  const [verifying, setVerifying] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [cooldown, setCooldown] = useState(RESEND_COOLDOWN);

  const inputRef = useRef<TextInput>(null);

  useEffect(() => {
    const t = setTimeout(() => inputRef.current?.focus(), 300);
    return () => clearTimeout(t);
  }, []);

  useEffect(() => {
    if (cooldown <= 0) return;
    const id = setInterval(() => setCooldown((c) => (c > 0 ? c - 1 : 0)), 1000);
    return () => clearInterval(id);
  }, [cooldown]);

  async function handleVerify() {
    if (code.length !== 6) {
      setError('Enter the 6-digit code.');
      return;
    }
    setVerifying(true);
    setError('');
    setNotice('');
    try {
      await authApi.verifyEmail({ code });
      const target = await applyProfileAndRoute(setTheme);
      navigation.reset({ index: 0, routes: [{ name: target }] });
    } catch (e: any) {
      setError(e?.message || 'Invalid or expired verification code.');
      setCode('');
      setVerifying(false);
    }
  }

  async function handleResend() {
    if (cooldown > 0 || resending) return;
    setResending(true);
    setError('');
    setNotice('');
    try {
      await authApi.resendEmailVerification();
      setNotice('A new code has been sent to your email.');
      setCooldown(RESEND_COOLDOWN);
    } catch (e: any) {
      setError(e?.message || 'Could not resend the code.');
    } finally {
      setResending(false);
    }
  }

  async function handleBackToLogin() {
    // Abandon the pending unverified session and return to login.
    await tokenStorage.remove();
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Check your email</Text>
      <Text style={styles.subtitle}>
        We sent a 6-digit verification code{email ? ` to ${email}` : ''}. Enter it to activate your account.
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
        title="Verify email"
        onPress={handleVerify}
        loading={verifying}
        disabled={code.length !== 6 || verifying}
        style={styles.verifyBtn}
      />

      <Pressable onPress={handleResend} disabled={cooldown > 0 || resending} hitSlop={8} style={styles.resend}>
        <Text style={[styles.resendText, (cooldown > 0 || resending) && styles.resendDisabled]}>
          {cooldown > 0 ? `Resend code in ${cooldown}s` : resending ? 'Sending…' : 'Resend code'}
        </Text>
      </Pressable>

      <Pressable onPress={handleBackToLogin} hitSlop={8} style={styles.back}>
        <Text style={styles.backText}>Back to login</Text>
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
