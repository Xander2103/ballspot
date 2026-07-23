import React, { useRef, useState } from 'react';
import { Text, StyleSheet, TextInput } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { applyProfileAndRoute } from '../app/authFlow';
import { useTheme } from '../theme/useTheme';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'Register'>;

type FieldErrors = {
  name?: string;
  username?: string;
  email?: string;
  password?: string;
};

export function RegisterScreen({ navigation }: Props) {
  const { setTheme } = useTheme();
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');

  const usernameRef = useRef<TextInput>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);

  async function handleRegister() {
    if (loading) return; // guard against double-submit (button + keyboard "done")
    setFieldErrors({});
    setFormError('');

    const errors: FieldErrors = {};
    if (!name.trim()) errors.name = 'Full name is required';
    if (!username.trim()) errors.username = 'Username is required';
    if (!email.trim()) errors.email = 'Email is required';
    if (!password) errors.password = 'Password is required';

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setLoading(true);
    try {
      const res = await authApi.register({ name: name.trim(), username: username.trim(), email: email.trim(), password });
      await tokenStorage.save(res.token);
      if (res.email_verified === true) {
        // Email verification disabled by config — account is already verified,
        // so skip the verification screen and route straight into the app.
        const route = await applyProfileAndRoute(setTheme);
        navigation.reset({ index: 0, routes: [{ name: route }] });
      } else {
        // New accounts must verify their email before full access.
        navigation.reset({ index: 0, routes: [{ name: 'EmailVerification', params: { email: email.trim() } }] });
      }
    } catch (e: any) {
      if (e?.errors) {
        const apiErrors: FieldErrors = {};
        for (const [field, messages] of Object.entries(e.errors)) {
          apiErrors[field as keyof FieldErrors] = (messages as string[]).join(', ');
        }
        setFieldErrors(apiErrors);
      } else {
        setFormError(e?.message || 'Registration failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>Create Account</Text>
      {formError ? <Text style={styles.formError}>{formError}</Text> : null}
      <AppInput
        label="Full Name"
        value={name}
        onChangeText={setName}
        autoCapitalize="words"
        error={fieldErrors.name}
        returnKeyType="next"
        onSubmitEditing={() => usernameRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={usernameRef}
        label="Username"
        value={username}
        onChangeText={setUsername}
        autoCapitalize="none"
        error={fieldErrors.username}
        returnKeyType="next"
        onSubmitEditing={() => emailRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={emailRef}
        label="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
        error={fieldErrors.email}
        returnKeyType="next"
        onSubmitEditing={() => passwordRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={passwordRef}
        label="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        error={fieldErrors.password}
        returnKeyType="done"
        onSubmitEditing={handleRegister}
      />
      <AppButton title="Create Account" onPress={handleRegister} loading={loading} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.lg },
  formError: { color: colors.error, fontSize: 14, marginBottom: spacing.md },
});
