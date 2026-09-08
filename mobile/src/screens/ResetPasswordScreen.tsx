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
import { parseResetInput, looksLikeResetLink } from '../utils/resetLink';
import { classifyResetError, mapAuthError, validatePasswordPair } from '../utils/authErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ResetPassword'>;

type FieldErrors = { email?: string; token?: string; password?: string; password_confirmation?: string };

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
  const [expiredReason, setExpiredReason] = useState<'expired' | 'invalid'>('expired');

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

    const next: FieldErrors = { ...validatePasswordPair(password, confirm) };
    if (!resolvedEmail) next.email = 'Email is required';
    if (!parsed) next.token = 'Paste the reset link (or the code from it) from your email';
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
      // Expired vs invalid link → the "link no longer works" screen with the
      // right sentence; field validation → on the field; a transient server
      // failure (reset_failed, nothing changed) → friendly retry text.
      const problem = classifyResetError(e);
      const info = mapAuthError(e, INVALID_LINK);
      if (problem) {
        setExpiredReason(problem);
        setState('expired');
      } else if (Object.keys(info.fieldErrors).length > 0) {
        const apiErrors: FieldErrors = {};
        for (const [field, text] of Object.entries(info.fieldErrors)) {
          if (field === 'email' || field === 'token' || field === 'password' || field === 'password_confirmation') apiErrors[field] = text;
          else if (!apiErrors.password && text) apiErrors.password = text;
        }
        setErrors(apiErrors);
      } else {
        setFormError(info.message);
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
          <Text style={[styles.title, styles.centerText]}>
            {expiredReason === 'expired' ? 'This link has expired' : 'This link no longer works'}
          </Text>
          <Text style={[styles.body, styles.centerText]}>
            {expiredReason === 'expired'
              ? 'Reset links are valid for a limited time. Request a new link and use the newest email.'
              : 'Reset links can only be used once and must match the email they were sent to. Request a new link and use the newest email.'}
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
        onChangeText={(t) => { setConfirm(t); setErrors((p) => ({ ...p, password_confirmation: undefined })); }}
        error={errors.password_confirmation}
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
