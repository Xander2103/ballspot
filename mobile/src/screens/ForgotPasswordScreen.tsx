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

type Props = NativeStackScreenProps<RootStackParamList, 'ForgotPassword'>;

export function ForgotPasswordScreen({ navigation }: Props) {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);

  async function handleSubmit() {
    if (!email.trim()) return;
    setLoading(true);
    try {
      // Backend always returns a generic success (no email enumeration),
      // so we show the same confirmation regardless of the result.
      await authApi.forgotPassword({ email: email.trim() });
    } catch {
      // Swallow errors on purpose — never reveal whether the email exists,
      // and never crash if mail is not configured.
    } finally {
      setLoading(false);
      setSent(true);
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
            The link contains a reset code. Open it, or enter the code on the next screen.
          </Text>
          <AppButton
            title="Enter reset code"
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
      <AppInput
        label="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        returnKeyType="send"
        onSubmitEditing={handleSubmit}
      />
      <AppButton title="Send reset link" onPress={handleSubmit} loading={loading} style={styles.btn} />
      <AppButton title="Back to login" variant="secondary" onPress={() => navigation.goBack()} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.md },
  body: { fontSize: 15, color: colors.textSecondary, marginBottom: spacing.lg, lineHeight: 22 },
  hint: { fontSize: 13, color: colors.textMuted, marginBottom: spacing.lg, textAlign: 'center', lineHeight: 19 },
  btn: { marginBottom: spacing.sm },
  confirmBox: { alignItems: 'center', paddingTop: spacing.xl },
  confirmIcon: { fontSize: 48, marginBottom: spacing.md },
  email: { color: colors.text, fontWeight: '700' },
});
