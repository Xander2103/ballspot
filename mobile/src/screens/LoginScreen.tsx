import React, { useState } from 'react';
import { View, Text, StyleSheet, Alert, Pressable } from 'react-native';
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

type Props = NativeStackScreenProps<RootStackParamList, 'Login'>;

export function LoginScreen({ navigation }: Props) {
  const { theme, setTheme } = useTheme();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleLogin() {
    if (!email || !password) { Alert.alert('Error', 'Please fill in all fields'); return; }
    setLoading(true);
    try {
      const result = await authApi.login({ email, password });

      // Email not verified yet → finish verification first (token already issued).
      if (isEmailVerificationRequired(result)) {
        await tokenStorage.save(result.token);
        navigation.reset({ index: 0, routes: [{ name: 'EmailVerification', params: { email } }] });
        return;
      }

      // Forced 2FA (config/admin): a login code was emailed. Go verify it.
      if (isTwoFactorRequired(result)) {
        navigation.navigate('LoginVerification', { verificationId: result.verification_id, email });
        return;
      }

      // Normal login — a token was returned directly.
      const target = await completeLogin(result.token, setTheme);
      navigation.reset({ index: 0, routes: [{ name: target }] });
    } catch (e: any) {
      Alert.alert('Login Failed', e?.message || 'Invalid credentials');
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
      <AppInput label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" />
      <AppInput label="Password" value={password} onChangeText={setPassword} secureTextEntry />
      <AppButton title="Login" onPress={handleLogin} loading={loading} style={styles.btn} />
      <Pressable onPress={() => navigation.navigate('ForgotPassword')} style={styles.forgot} hitSlop={8}>
        <Text style={[styles.forgotText, { color: theme.accent }]}>Forgot password?</Text>
      </Pressable>
      <AppButton title="Create Account" onPress={() => navigation.navigate('Register')} variant="secondary" />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { alignItems: 'center', paddingVertical: spacing.xxl },
  logo: { fontSize: 36, fontWeight: '800', marginBottom: spacing.sm },
  tagline: { fontSize: 16 },
  btn: { marginBottom: spacing.sm },
  forgot: { alignSelf: 'center', paddingVertical: spacing.sm, marginBottom: spacing.sm },
  forgotText: { fontSize: 14, fontWeight: '600' },
});
