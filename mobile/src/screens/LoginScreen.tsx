import React, { useState } from 'react';
import { View, Text, StyleSheet, Alert, Pressable } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'Login'>;

export function LoginScreen({ navigation }: Props) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleLogin() {
    if (!email || !password) { Alert.alert('Error', 'Please fill in all fields'); return; }
    setLoading(true);
    try {
      const { token } = await authApi.login({ email, password });
      await tokenStorage.save(token);
      navigation.reset({ index: 0, routes: [{ name: 'Home' }] });
    } catch (e: any) {
      Alert.alert('Login Failed', e?.message || 'Invalid credentials');
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <View style={styles.header}>
        <Text style={styles.logo}>⚽ BallSpot</Text>
        <Text style={styles.tagline}>Find the ball. Beat your friends.</Text>
      </View>
      <AppInput label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoComplete="email" />
      <AppInput label="Password" value={password} onChangeText={setPassword} secureTextEntry />
      <AppButton title="Login" onPress={handleLogin} loading={loading} style={styles.btn} />
      <Pressable onPress={() => navigation.navigate('ForgotPassword')} style={styles.forgot} hitSlop={8}>
        <Text style={styles.forgotText}>Forgot password?</Text>
      </Pressable>
      <AppButton title="Create Account" onPress={() => navigation.navigate('Register')} variant="secondary" />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { alignItems: 'center', paddingVertical: spacing.xxl },
  logo: { fontSize: 36, fontWeight: '800', color: colors.primary, marginBottom: spacing.sm },
  tagline: { fontSize: 16, color: colors.textSecondary },
  btn: { marginBottom: spacing.sm },
  forgot: { alignSelf: 'center', paddingVertical: spacing.sm, marginBottom: spacing.sm },
  forgotText: { color: colors.accent, fontSize: 14, fontWeight: '600' },
});
