import React, { useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, TextInput, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { applyProfileAndRoute } from '../app/authFlow';
import { signOut } from '../app/signOut';
import { useTheme } from '../theme/useTheme';
import { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage } from '../utils/apiError';
import {
  buildVerifyPayload,
  classifyVerificationError,
  resendNotice,
  resolveVerificationTarget,
} from '../utils/verificationFlow';

type Props = NativeStackScreenProps<RootStackParamList, 'EmailVerification'>;

const RESEND_COOLDOWN = 60;

/**
 * Registration step 2 — confirm the email address with the 6-digit code.
 * The auth token is already stored; verifying unlocks full app access.
 *
 * The screen asks the server which account the stored token belongs to and
 * shows THAT email. If it differs from the account the user just created
 * (a stale session from a previous login on this device), it says so and
 * offers "Log in again" instead of rejecting a perfectly good code.
 * Every code the server sent recently stays valid, so "resend" is safe.
 */
export function EmailVerificationScreen({ route, navigation }: Props) {
  const { email: routeEmail, codeSent } = route.params ?? {};
  const { theme, setTheme } = useTheme();
  const styles = createStyles(theme);

  const [code, setCode] = useState('');
  const [targetEmail, setTargetEmail] = useState<string | null>(routeEmail ?? null);
  const [mismatch, setMismatch] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(
    codeSent === false ? 'We could not send a new email just now. Enter the code you already received, or tap "Resend code".' : '',
  );
  // No cooldown when the server told us nothing was sent — the user needs the
  // resend button right away.
  const [cooldown, setCooldown] = useState(codeSent === false ? 0 : RESEND_COOLDOWN);

  const inputRef = useRef<TextInput>(null);

  useEffect(() => {
    const t = setTimeout(() => inputRef.current?.focus(), 300);
    return () => clearTimeout(t);
  }, []);

  // Source of truth for "which account am I verifying": the token, not the
  // navigation params.
  useEffect(() => {
    let cancelled = false;
    authApi.verificationStatus()
      .then(async (s) => {
        if (cancelled) return;
        if (s.email_verified) {
          // Verified already (other device / earlier attempt) — just go in.
          const target = await applyProfileAndRoute(setTheme);
          navigation.reset({ index: 0, routes: [{ name: target }] });
          return;
        }
        const resolved = resolveVerificationTarget(routeEmail, s.email);
        setTargetEmail(resolved.email);
        setMismatch(resolved.mismatch);
        if (resolved.mismatch) {
          setError(classifyVerificationError({ status: 409, reason: 'session_mismatch' }).message);
        }
        if (s.resend_available_in_seconds !== undefined && codeSent !== false) {
          setCooldown(Math.min(RESEND_COOLDOWN, Math.max(0, s.resend_available_in_seconds)));
        }
      })
      .catch((e: unknown) => {
        if (cancelled) return;
        if ((e as { status?: number })?.status === 401) {
          setError('Your session has expired. Please log in again to continue verifying.');
        }
        // Any other failure: keep the route email; the verify call still works.
      });
    return () => { cancelled = true; };
  }, [routeEmail]);

  useEffect(() => {
    if (cooldown <= 0) return;
    const id = setInterval(() => setCooldown((c) => (c > 0 ? c - 1 : 0)), 1000);
    return () => clearInterval(id);
  }, [cooldown]);

  async function goToLogin() {
    // Abandon the pending session entirely so the next login/register starts clean.
    await signOut();
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  }

  async function handleVerify() {
    if (verifying || mismatch) return;
    const payload = buildVerifyPayload(code, targetEmail);
    if (!payload) {
      setError('Enter the 6-digit code.');
      return;
    }
    setVerifying(true);
    setError('');
    setNotice('');
    try {
      await authApi.verifyEmail(payload);
      const target = await applyProfileAndRoute(setTheme);
      navigation.reset({ index: 0, routes: [{ name: target }] });
    } catch (e: unknown) {
      const failure = classifyVerificationError(e);
      setError(failure.message);
      if (failure.kind === 'session_mismatch') {
        setMismatch(true);
      } else if (failure.kind === 'unauthorized') {
        setTimeout(goToLogin, 1500);
        return;
      } else if (failure.kind === 'wrong_code') {
        setCode('');
      } else if (failure.kind === 'expired' || failure.kind === 'locked' || failure.kind === 'no_code') {
        setCode('');
        setCooldown(0); // the fix is a resend — do not make them wait for it
      }
      setVerifying(false);
    }
  }

  async function handleResend() {
    if (cooldown > 0 || resending || mismatch) return;
    setResending(true);
    setError('');
    setNotice('');
    try {
      const res = await authApi.resendEmailVerification();
      if (res.email_verified) {
        // Verified elsewhere in the meantime — just go in.
        const target = await applyProfileAndRoute(setTheme);
        navigation.reset({ index: 0, routes: [{ name: target }] });
        return;
      }
      // The server names the account it mailed; trust that over the params.
      const to = res.email ?? targetEmail;
      if (res.email) setTargetEmail(res.email);
      setNotice(resendNotice(to));
      setCooldown(RESEND_COOLDOWN);
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status;
      if (status === 401) {
        setError('Your session has expired. Please log in again to continue verifying.');
        setTimeout(goToLogin, 1500);
        return;
      }
      setError(getApiErrorMessage(e, 'Could not resend the code. Please try again in a moment.'));
    } finally {
      setResending(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Check your email</Text>
      <Text style={styles.subtitle}>
        We sent a 6-digit verification code{targetEmail ? ` to ${targetEmail}` : ''}. Enter it to activate your account.
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
        editable={!mismatch}
        onSubmitEditing={handleVerify}
      />

      {error ? <Text style={styles.error}>{error}</Text> : null}
      {notice ? <Text style={styles.notice}>{notice}</Text> : null}

      {mismatch ? (
        <AppButton title="Log in again" onPress={goToLogin} style={styles.verifyBtn} />
      ) : (
        <AppButton
          title="Verify email"
          onPress={handleVerify}
          loading={verifying}
          disabled={code.length !== 6 || verifying}
          style={styles.verifyBtn}
        />
      )}

      <Pressable onPress={handleResend} disabled={cooldown > 0 || resending || mismatch} hitSlop={8} style={styles.resend}>
        <Text style={[styles.resendText, (cooldown > 0 || resending || mismatch) && styles.resendDisabled]}>
          {cooldown > 0 ? `Resend code in ${cooldown}s` : resending ? 'Sending…' : 'Resend code'}
        </Text>
      </Pressable>

      <Text style={styles.hint}>Didn't get it? Check your spam folder. Codes stay valid for an hour, and older codes keep working after a resend.</Text>

      <Pressable onPress={goToLogin} hitSlop={8} style={styles.back}>
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
    hint: { color: theme.textMuted, fontSize: 12, textAlign: 'center', marginBottom: spacing.sm },
    back: { alignSelf: 'center', paddingVertical: spacing.sm },
    backText: { color: theme.textSecondary, fontSize: 14, fontWeight: '600' },
  });
}
