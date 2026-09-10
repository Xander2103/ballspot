import React, { useEffect, useRef, useState } from 'react';
import { Text, StyleSheet, TextInput, Pressable, View, Linking } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppInput } from '../components/AppInput';
import { AppButton } from '../components/AppButton';
import { LanguagePicker } from '../components/LanguagePicker';
import { authApi } from '../api/authApi';
import { configApi, DEFAULT_APP_CONFIG } from '../api/configApi';
import { useI18n, rememberConfigDefault } from '../i18n';
import { tokenStorage } from '../storage/tokenStorage';
import { applyProfileAndRoute } from '../app/authFlow';
import { signOut } from '../app/signOut';
import { prepareForNewAccount, adoptToken } from '../utils/verificationFlow';
import { mapAuthError, validatePasswordPair } from '../utils/authErrors';
import { defaultLanguageForDevice, LanguageCode } from '../utils/language';
import { useTheme } from '../theme/useTheme';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

type Props = NativeStackScreenProps<RootStackParamList, 'Register'>;

const API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api';
const WEB_BASE = process.env.EXPO_PUBLIC_WEB_URL ?? API_BASE.replace(/\/api$/, '');

type FieldErrors = {
  name?: string;
  username?: string;
  email?: string;
  password?: string;
  password_confirmation?: string;
  preferred_language?: string;
  beta_code?: string;
};

const KNOWN_FIELDS: (keyof FieldErrors)[] = [
  'name', 'username', 'email', 'password', 'password_confirmation', 'preferred_language', 'beta_code',
];

export function RegisterScreen({ navigation }: Props) {
  const { setTheme } = useTheme();
  const { t, setLocale } = useI18n();
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [language, setLanguage] = useState<LanguageCode>(() => defaultLanguageForDevice());
  const [betaCode, setBetaCode] = useState('');
  const [agreed, setAgreed] = useState(false);
  const [loading, setLoading] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  // The beta-code field is hidden for the public launch. It only appears when
  // the backend reports the gate as ON (private beta) — or, as a fallback, when
  // the backend rejects a registration for a missing code.
  const [betaGate, setBetaGate] = useState(DEFAULT_APP_CONFIG.beta_gate);
  const [minimumAge, setMinimumAge] = useState(DEFAULT_APP_CONFIG.minimum_age);

  const usernameRef = useRef<TextInput>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);
  const confirmRef = useRef<TextInput>(null);

  useEffect(() => {
    let cancelled = false;
    configApi.get()
      .then((cfg) => {
        if (cancelled) return;
        setBetaGate(!!cfg.beta_gate);
        if (cfg.minimum_age) setMinimumAge(cfg.minimum_age);
        rememberConfigDefault(cfg.default_language);
      })
      .catch(() => { /* keep defaults: gate hidden, the server still validates */ });
    return () => { cancelled = true; };
  }, []);

  function clearField(field: keyof FieldErrors) {
    setFieldErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));
  }

  async function handleRegister() {
    if (loading) return; // guard against double-submit (button + keyboard "done")
    setFieldErrors({});
    setFormError('');

    const errors: FieldErrors = { ...validatePasswordPair(password, confirm) };
    if (!name.trim()) errors.name = t('auth.register.validation.fullNameRequired');
    if (!username.trim()) errors.username = t('errors.validation.usernameRequired');
    if (!email.trim()) errors.email = t('errors.validation.emailRequired');
    if (betaGate && !betaCode.trim()) errors.beta_code = t('auth.register.validation.betaCodeRequired');

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    if (!agreed) {
      setFormError(t('auth.register.consentRequired'));
      return;
    }

    setLoading(true);
    try {
      // A previous account on this device must not leak into the new one: no
      // stale Authorization header on the register call, no old reminders or
      // theme, and no chance that a failed registration leaves the old session
      // half-alive. (Being on this screen already means "switch account".)
      await prepareForNewAccount(tokenStorage, signOut);

      const res = await authApi.register({
        name: name.trim(),
        username: username.trim(),
        email: email.trim(),
        password,
        password_confirmation: confirm,
        preferred_language: language,
        terms_accepted: true,
        age_confirmed: true,
        ...(betaGate && betaCode.trim() ? { beta_code: betaCode.trim() } : {}),
      });
      // Persist the NEW token and prove it is what the device now holds — the
      // verification screen must never run against a leftover credential.
      await adoptToken(tokenStorage, res.token);
      if (res.email_verified === true) {
        // Email verification disabled by config — account is already verified,
        // so skip the verification screen and route straight into the app.
        const route = await applyProfileAndRoute(setTheme);
        navigation.reset({ index: 0, routes: [{ name: route }] });
      } else {
        // New accounts must verify their email before full access.
        navigation.reset({
          index: 0,
          routes: [{ name: 'EmailVerification', params: { email: email.trim(), codeSent: res.code_sent !== false } }],
        });
      }
    } catch (e: unknown) {
      // Known account errors (email taken, username taken, passwords do not
      // match, …) land on their field with friendly copy; anything else is
      // one clean sentence — never raw backend text.
      const info = mapAuthError(e, t('auth.register.failed'));
      const apiErrors: FieldErrors = {};
      const other: string[] = [];
      for (const [field, text] of Object.entries(info.fieldErrors)) {
        if (KNOWN_FIELDS.includes(field as keyof FieldErrors)) {
          apiErrors[field as keyof FieldErrors] = text;
        } else if (text) {
          other.push(text);
        }
      }
      // The server asked for a beta code: reveal the field even if /config
      // said the gate was off (config drift between deploys).
      if (apiErrors.beta_code) setBetaGate(true);
      setFieldErrors(apiErrors);
      if (Object.keys(apiErrors).length === 0) {
        setFormError(info.message);
      } else if (other.length) {
        setFormError(other[0]);
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{t('auth.register.title')}</Text>
      {formError ? <Text style={styles.formError}>{formError}</Text> : null}
      <AppInput
        label={t('auth.register.fields.fullName')}
        value={name}
        onChangeText={(t) => { setName(t); clearField('name'); }}
        autoCapitalize="words"
        error={fieldErrors.name}
        returnKeyType="next"
        onSubmitEditing={() => usernameRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={usernameRef}
        label={t('common.labels.username')}
        value={username}
        onChangeText={(t) => { setUsername(t); clearField('username'); }}
        autoCapitalize="none"
        autoCorrect={false}
        error={fieldErrors.username}
        returnKeyType="next"
        onSubmitEditing={() => emailRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={emailRef}
        label={t('common.labels.email')}
        value={email}
        onChangeText={(t) => { setEmail(t); clearField('email'); }}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        error={fieldErrors.email}
        returnKeyType="next"
        onSubmitEditing={() => passwordRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={passwordRef}
        label={t('auth.register.fields.password')}
        value={password}
        onChangeText={(t) => { setPassword(t); clearField('password'); clearField('password_confirmation'); }}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        error={fieldErrors.password}
        returnKeyType="next"
        onSubmitEditing={() => confirmRef.current?.focus()}
        blurOnSubmit={false}
      />
      <AppInput
        ref={confirmRef}
        label={t('auth.register.fields.confirmPassword')}
        value={confirm}
        onChangeText={(t) => { setConfirm(t); clearField('password_confirmation'); }}
        secureTextEntry
        autoComplete="new-password"
        textContentType="newPassword"
        error={fieldErrors.password_confirmation}
        returnKeyType={betaGate ? 'next' : 'done'}
        onSubmitEditing={betaGate ? undefined : handleRegister}
      />
      {betaGate ? (
        <AppInput
          label={t('auth.register.fields.betaCode')}
          value={betaCode}
          onChangeText={(t) => { setBetaCode(t); clearField('beta_code'); }}
          autoCapitalize="characters"
          autoCorrect={false}
          error={fieldErrors.beta_code}
          returnKeyType="done"
          onSubmitEditing={handleRegister}
        />
      ) : null}

      <LanguagePicker
        label={t('common.language.preferred')}
        value={language}
        onChange={(code) => { setLanguage(code); setLocale(code); clearField('preferred_language'); }}
        disabled={loading}
      />
      {fieldErrors.preferred_language ? <Text style={styles.fieldError}>{fieldErrors.preferred_language}</Text> : null}

      {/* Terms/Privacy consent — required before account creation. */}
      <Pressable
        style={styles.consentRow}
        onPress={() => setAgreed((v) => !v)}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: agreed }}
      >
        <View style={[styles.checkbox, agreed && styles.checkboxChecked]}>
          {agreed ? <Text style={styles.checkboxMark}>✓</Text> : null}
        </View>
        <Text style={styles.consentText}>
          {t('auth.register.consent.ageAndTerms', { age: minimumAge })}{' '}
          <Text style={styles.link} onPress={() => Linking.openURL(`${WEB_BASE}/terms`)}>
            {t('common.legal.termsShort')}
          </Text>{' '}
          {t('auth.register.consent.andRead')}{' '}
          <Text style={styles.link} onPress={() => Linking.openURL(`${WEB_BASE}/privacy`)}>
            {t('common.legal.privacy')}
          </Text>
          .
        </Text>
      </Pressable>

      <AppButton title={t('auth.register.title')} onPress={handleRegister} loading={loading} disabled={!agreed} />
      <Pressable onPress={() => navigation.navigate('Login')} style={styles.loginLink} hitSlop={8} disabled={loading}>
        <Text style={styles.loginLinkText}>{t('auth.register.haveAccount')}</Text>
      </Pressable>
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: 24, fontWeight: '700', color: colors.text, marginBottom: spacing.lg },
  formError: { color: colors.error, fontSize: 14, marginBottom: spacing.md },
  fieldError: { color: colors.error, fontSize: 12, marginTop: -spacing.sm, marginBottom: spacing.md },
  consentRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    marginBottom: spacing.md,
    gap: spacing.sm,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 5,
    borderWidth: 2,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  checkboxChecked: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  checkboxMark: { color: '#fff', fontSize: 14, fontWeight: '700' },
  consentText: { flex: 1, color: colors.textSecondary, fontSize: 13, lineHeight: 19 },
  link: { color: colors.primary, fontWeight: '600' },
  loginLink: { alignSelf: 'center', paddingVertical: spacing.md },
  loginLinkText: { color: colors.textSecondary, fontSize: 14, fontWeight: '600' },
});
