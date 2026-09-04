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

type Props = NativeStackScreenProps<RootStackParamList, 'ForgotPassword'>;

export function ForgotPasswordScreen({ navigation }: Props) {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit() {
    if (loading) return;
    const trimmed = email.trim();
    if (!trimmed) {
      setError('Please enter your email address.');
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
        setError(getApiErrorMessage(e, 'We could not send the reset email right now. Please try again.'));
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
          <Text style={styles.title}>Check your email</Text>
          <Text style={styles.body}>
            If an account exists for{'\n'}
            <Text style={styles.email}>{email.trim()}</Text>,{'\n'}
            we've sent a link to reset your password.
          </Text>
          <Text style={styles.hint}>
            Open the link on any device to choose a new password, or copy the link and paste it on the next screen.
            The link expires after a while — if it stops working, request a new one here.
          </Text>
          <AppButton
            title="I have the link"
            onPress={() => navigation.navigate('ResetPassword', { email: email.trim() })}
            style={styles.btn}
          />
          <AppButton title="Back to login" variant="secondary" onPress={() => navigation.navigate('Login')} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Forgot password?</Text>
      <Text style={styles.body}>
        Enter the email for your account and we'll send you a link to reset your password.
      </Text>
      {error ? <Text style={styles.formError}>{error}</Text> : null}
      <AppInput
        label="Email"
        value={email}
        onChangeText={(t) => { setEmail(t); setError(''); }}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        returnKeyType="send"
        onSubmitEditing={handleSubmit}
      />
      <AppButton title="Send reset link" onPress={handleSubmit} loading={loading} style={styles.btn} />
      <AppButton title="Back to login" variant="secondary" onPress={() => navigation.goBack()} disabled={loading} />
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
