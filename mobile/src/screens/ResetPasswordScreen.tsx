import React, { useEffect, useState } from 'react';
import { Text, StyleSheet, View } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage } from '../utils/apiError';
import { parseResetInput, looksLikeResetLink } from '../utils/resetLink';

type Props = NativeStackScreenProps<RootStackParamList, 'ResetPassword'>;

type FieldErrors = { email?: string; token?: string; password?: string };

const INVALID_LINK = 'This reset link is invalid or has expired. Request a new one and use the newest email.';

/**
 * Reached three ways: from "Forgot password" (user pastes the link), from the
 * ballpicker:// deep link on the web reset page (token + email pre-filled), or
 * from a universal link where configured. The "reset code" is the 64-character
 * token from the email link — pasting the whole link is fine, we extract it.
 */
export function ResetPasswordScreen({ navigation, route }: Props) {
  const [email, setEmail] = useState(route.params?.email ?? '');
  const [linkInput, setLinkInput] = useState(route.params?.token ?? '');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  const [state, setState] = useState<'form' | 'done' | 'expired'>('form');

  // Deep link opened while the screen is already mounted.
  useEffect(() => {
    if (route.params?.token) setLinkInput(route.params.token);
    if (route.params?.email) setEmail(route.params.email);
  }, [route.params?.token, route.params?.email]);

  function handleLinkChange(text: string) {
    setLinkInput(text);
    setErrors((prev) => ({ ...prev, token: undefined }));
    setFormError('');
    // Pasting the full link also fills the email when it carries one.
    if (looksLikeResetLink(text)) {
      const parsed = parseResetInput(text);
      if (parsed?.email && !email.trim()) setEmail(parsed.email);
    }
  }

  async function handleSubmit() {
    if (loading) return;
    setErrors({});
    setFormError('');

    const parsed = parseResetInput(linkInput);
    const resolvedEmail = (email.trim() || parsed?.email || '').trim();

    const next: FieldErrors = {};
    if (!resolvedEmail) next.email = 'Email is required';
    if (!parsed) next.token = 'Paste the reset link (or the code from it) from your email';
    if (!password) next.password = 'Password is required';
    else if (password.length < 8) next.password = 'Password must be at least 8 characters';
    else if (password !== confirm) next.password = 'Passwords do not match';
    if (Object.keys(next).length > 0) { setErrors(next); return; }

    setLoading(true);
    try {
      await authApi.resetPassword({
        email: resolvedEmail,
        token: parsed!.token,
        password,
        password_confirmation: confirm,
      });
      setState('done');
    } catch (e: unknown) {
      const err = e as { status?: number; errors?: Record<string, unknown>; reason?: string };
      if (err?.errors && typeof err.errors === 'object') {
        const apiErrors: FieldErrors = {};
        for (const [field, messages] of Object.entries(err.errors)) {
          const text = Array.isArray(messages) ? messages.filter((m) => typeof m === 'string').join(' ') : String(messages ?? '');
          if (field === 'email' || field === 'token' || field === 'password') apiErrors[field] = text;
          else if (!apiErrors.password && text) apiErrors.password = text;
        }
        setErrors(apiErrors);
      } else if (err?.status === 422 || err?.reason === 'invalid_or_expired') {
        setState('expired');
      } else {
        setFormError(getApiErrorMessage(e, INVALID_LINK));
      }
    } finally {
      setLoading(false);
    }
  }

  if (state === 'done') {
    return (
      <Screen scroll padding>
        <View style={styles.centerBox}>
          <Text style={styles.bigIcon}>✅</Text>
          <Text style={[styles.title, styles.centerText]}>Password updated</Text>
          <Text style={[styles.body, styles.centerText]}>
            Your password has been changed and every other session has been signed out. Log in with your new password.
          </Text>
          <AppButton title="Go to login" onPress={() => navigation.reset({ index: 0, routes: [{ name: 'Login' }] })} />
        </View>
      </Screen>
    );
  }

  if (state === 'expired') {
    return (
      <Screen scroll padding>
        <View style={styles.centerBox}>
          <Text style={styles.bigIcon}>⏰</Text>
          <Text style={[styles.title, styles.centerText]}>This link no longer works</Text>
          <Text style={[styles.body, styles.centerText]}>
            Reset links are valid for a limited time and can only be used once. Request a new link and use the newest email.
          </Text>
          <AppButton title="Request a new link" onPress={() => navigation.navigate('ForgotPassword')} style={styles.btn} />
          <AppButton title="Try again" variant="secondary" onPress={() => { setState('form'); setLinkInput(''); }} style={styles.btn} />
          <AppButton title="Back to login" variant="secondary" onPress={() => navigation.reset({ index: 0, routes: [{ name: 'Login' }] })} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Reset password</Text>
      <Text style={styles.body}>
        Paste the reset link from your email below (the code inside it works too), then choose a new password.
      </Text>
      {formError ? <Text style={styles.formError}>{formError}</Text> : null}
      <AppInput
        label="Email"
        value={email}
        onChangeText={(t) => { setEmail(t); setErrors((p) => ({ ...p, email: undefined })); }}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        error={errors.email}
      />
      <AppInput
        label="Reset link or code"
        value={linkInput}
        onChangeText={handleLinkChange}
        autoCapitalize="none"
        autoCorrect={false}
        placeholder="https://…/reset-password?token=…"
        error={errors.token}
      />
      <AppInput
        label="New password (at least 8 characters)"
        value={password}
        onChangeText={(t) => { setPassword(t); setErrors((p) => ({ ...p, password: undefined })); }}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        error={errors.password}
      />
      <AppInput
        label="Confirm new password"
        value={confirm}
        onChangeText={setConfirm}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        returnKeyType="done"
        onSubmitEditing={handleSubmit}
      />
      <AppButton title="Set new password" onPress={handleSubmit} loading={loading} style={styles.btn} />
      <AppButton title="Request a new link" variant="secondary" onPress={() => navigation.navigate('ForgotPassword')} style={styles.btn} disabled={loading} />
      <AppButton title="Back to login" variant="secondary" onPress={() => navigation.reset({ index: 0, routes: [{ name: 'Login' }] })} disabled={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.md, textAlign: 'left' },
  body: { fontSize: 15, color: colors.textSecondary, marginBottom: spacing.lg, lineHeight: 22 },
  centerText: { textAlign: 'center' },
  centerBox: { paddingTop: spacing.xl },
  bigIcon: { fontSize: 48, marginBottom: spacing.md, textAlign: 'center' },
  formError: { color: colors.error, fontSize: 14, marginBottom: spacing.md },
  btn: { marginBottom: spacing.sm },
});
