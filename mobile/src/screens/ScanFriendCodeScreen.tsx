import React, { useRef, useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'ScanFriendCode'>;

/** Accepts a bare code or a `ballpicker:friend:CODE` payload. */
function parseFriendCode(raw: string): string | null {
  const value = raw.trim().replace(/^ballpicker:friend:/i, '').toUpperCase();
  return /^[A-HJ-NP-Z2-9]{6,12}$/.test(value) ? value : null;
}

export function ScanFriendCodeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const [permission, requestPermission] = useCameraPermissions();
  const [error, setError] = useState('');
  // Guards against onBarcodeScanned firing repeatedly for the same code.
  const handled = useRef(false);

  // Permission is requested here — only when the user opts into scanning.
  if (!permission) {
    return (
      <Screen padding>
        <Text style={styles.body}>{t('friends.scan.preparing')}</Text>
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen padding>
        <Text style={styles.title}>{t('friends.scan.permissionTitle')}</Text>
        <Text style={styles.body}>
          {permission.canAskAgain ? t('friends.scan.permissionAsk') : t('friends.scan.permissionDenied')}
        </Text>
        {permission.canAskAgain ? (
          <AppButton title={t('friends.scan.allowCamera')} onPress={requestPermission} style={{ marginTop: spacing.lg }} />
        ) : null}
        <AppButton
          title={t('friends.scan.enterManually')}
          onPress={() => navigation.navigate('Home', { screen: 'Friends' })}
          variant="secondary"
          style={{ marginTop: spacing.sm }}
        />
      </Screen>
    );
  }

  return (
    <View style={styles.fill}>
      <CameraView
        style={styles.fill}
        facing="back"
        barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
        onBarcodeScanned={({ data }) => {
          if (handled.current) return;
          const code = parseFriendCode(String(data ?? ''));
          if (!code) {
            setError(t('friends.scan.invalidCode'));
            return;
          }
          handled.current = true;
          navigation.navigate('Home', { screen: 'Friends', params: { scannedCode: code } });
        }}
      />
      <View style={styles.overlay}>
        <Text style={styles.overlayText}>{t('friends.scan.hint')}</Text>
        {error ? <Text style={styles.overlayError}>{error}</Text> : null}
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    fill: { flex: 1, backgroundColor: '#000000' },
    title: { fontSize: 20, fontWeight: '800', color: theme.text, marginBottom: spacing.sm },
    body: { fontSize: 14, color: theme.textSecondary, lineHeight: 20 },
    overlay: { position: 'absolute', left: 0, right: 0, bottom: 40, padding: spacing.lg },
    overlayText: { color: '#ffffff', fontSize: 14, textAlign: 'center' },
    overlayError: { color: '#ff8a80', fontSize: 13, textAlign: 'center', marginTop: spacing.sm },
  });
}
