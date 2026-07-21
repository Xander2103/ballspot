import React, { useState } from 'react';
import { Text, StyleSheet, Alert } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'ResetPassword'>;

type FieldErrors = { email?: string; token?: string; password?: string };

export function ResetPasswordScreen({ navigation, route }: Props) {
  const [email, setEmail] = useState(route.params?.email ?? '');
  const [token, setToken] = useState(route.params?.token ?? '');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');

  async function handleSubmit() {
    setErrors({});
    setFormError('');

    const next: FieldErrors = {};
    if (!email.trim()) next.email = 'Email is required';
    if (!token.trim()) next.token = 'Reset code is required';
    if (!password) next.password = 'Password is required';
    else if (password.length < 8) next.password = 'Password must be at least 8 characters';
    else if (password !== confirm) next.password = 'Passwords do not match';
    if (Object.keys(next).length > 0) { setErrors(next); return; }

    setLoading(true);
    try {
      await authApi.resetPassword({
        email: email.trim(),
        token: token.trim(),
        password,
        password_confirmation: confirm,
      });
      Alert.alert('Password reset', 'Your password has been reset. Please log in.', [
        { text: 'OK', onPress: () => navigation.reset({ index: 0, routes: [{ name: 'Login' }] }) },
      ]);
    } catch (e: any) {
      if (e?.errors) {
        const apiErrors: FieldErrors = {};
        for (const [field, messages] of Object.entries(e.errors)) {
          apiErrors[field as keyof FieldErrors] = (messages as string[]).join(', ');
        }
        setErrors(apiErrors);
      } else {
        setFormError(e?.message || 'This reset link is invalid or has expired.');
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Reset password</Text>
      <Text style={styles.body}>
        Enter the reset code from your email along with your new password.
      </Text>
      {formError ? <Text style={styles.formError}>{formError}</Text> : null}
      <AppInput
        label="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
        error={errors.email}
      />
      <AppInput label="Reset code" value={token} onChangeText={setToken} autoCapitalize="none" error={errors.token} />
      <AppInput label="New password" value={password} onChangeText={setPassword} secureTextEntry error={errors.password} />
      <AppInput label="Confirm new password" value={confirm} onChangeText={setConfirm} secureTextEntry />
      <AppButton title="Reset password" onPress={handleSubmit} loading={loading} style={styles.btn} />
      <AppButton title="Back to login" variant="secondary" onPress={() => navigation.navigate('Login')} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.md },
  body: { fontSize: 15, color: colors.textSecondary, marginBottom: spacing.lg, lineHeight: 22 },
  formError: { color: colors.error, fontSize: 14, marginBottom: spacing.md },
  btn: { marginBottom: spacing.sm },
});
