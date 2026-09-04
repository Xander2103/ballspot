import React, { useState } from 'react';
import { View, Text, StyleSheet, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { isTwoFactorRequired, isEmailVerificationRequired } from '../types/auth';
import { completeLogin } from '../app/authFlow';
import { tokenStorage } from '../storage/tokenStorage';
import { useTheme } from '../theme/useTheme';
import { spacing } from '../theme/spacing';
import { getApiErrorMessage } from '../utils/apiError';

type Props = NativeStackScreenProps<RootStackParamList, 'Login'>;

export function LoginScreen({ navigation }: Props) {
  const { theme, setTheme } = useTheme();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleLogin() {
    if (loading) return; // guard against double-submit (button + keyboard "go")
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      setError('Please enter your email and password.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const result = await authApi.login({ email: trimmedEmail, password });

      // Email not verified yet → finish verification first (token already issued).
      if (isEmailVerificationRequired(result)) {
        await tokenStorage.save(result.token);
        navigation.reset({
          index: 0,
          routes: [{ name: 'EmailVerification', params: { email: trimmedEmail, codeSent: result.code_sent } }],
        });
        return;
      }

      // Forced 2FA (config/admin): a login code was emailed. Go verify it.
      if (isTwoFactorRequired(result)) {
        navigation.navigate('LoginVerification', { verificationId: result.verification_id, email: trimmedEmail });
        return;
      }

      // Normal login — a token was returned directly.
      const target = await completeLogin(result.token, setTheme);
      navigation.reset({ index: 0, routes: [{ name: target }] });
    } catch (e: unknown) {
      // 422 "Invalid credentials." is the only expected failure; everything
      // else (offline, 429, 5xx) is reduced to one clean sentence.
      setError(getApiErrorMessage(e, 'Login failed. Please check your details and try again.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <View style={styles.header}>
        <Text style={[styles.logo, { color: theme.primary }]}>⚽ BallPicker</Text>
        <Text style={[styles.tagline, { color: theme.textSecondary }]}>Find the ball. Beat your friends.</Text>
      </View>
      {error ? <Text style={[styles.formError, { color: theme.danger }]}>{error}</Text> : null}
      <AppInput
        label="Email"
        value={email}
        onChangeText={(t) => { setEmail(t); setError(''); }}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        textContentType="username"
        returnKeyType="next"
      />
      <AppInput
        label="Password"
        value={password}
        onChangeText={(t) => { setPassword(t); setError(''); }}
        secureTextEntry
        autoComplete="password"
        textContentType="password"
        returnKeyType="go"
        onSubmitEditing={handleLogin}
      />
      <AppButton title="Login" onPress={handleLogin} loading={loading} style={styles.btn} />
      <Pressable onPress={() => navigation.navigate('ForgotPassword')} style={styles.forgot} hitSlop={8}>
        <Text style={[styles.forgotText, { color: theme.accent }]}>Forgot password?</Text>
      </Pressable>
      <AppButton title="Create Account" onPress={() => navigation.navigate('Register')} variant="secondary" disabled={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { alignItems: 'center', paddingVertical: spacing.xxl },
  logo: { fontSize: 36, fontWeight: '800', marginBottom: spacing.sm },
  tagline: { fontSize: 16 },
  formError: { fontSize: 14, marginBottom: spacing.md, textAlign: 'center' },
  btn: { marginBottom: spacing.sm },
  forgot: { alignSelf: 'center', paddingVertical: spacing.sm, marginBottom: spacing.sm },
  forgotText: { fontSize: 14, fontWeight: '600' },
});
