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

type Props = NativeStackScreenProps<RootStackParamList, 'ScanFriendCode'>;

/** Accepts a bare code or a `ballpicker:friend:CODE` payload. */
function parseFriendCode(raw: string): string | null {
  const value = raw.trim().replace(/^ballpicker:friend:/i, '').toUpperCase();
  return /^[A-HJ-NP-Z2-9]{6,12}$/.test(value) ? value : null;
}

export function ScanFriendCodeScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const [permission, requestPermission] = useCameraPermissions();
  const [error, setError] = useState('');
  // Guards against onBarcodeScanned firing repeatedly for the same code.
  const handled = useRef(false);

  // Permission is requested here — only when the user opts into scanning.
  if (!permission) {
    return (
      <Screen padding>
        <Text style={styles.body}>Preparing the camera…</Text>
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen padding>
        <Text style={styles.title}>Camera access needed</Text>
        <Text style={styles.body}>
          {permission.canAskAgain
            ? 'BallPicker needs your camera to scan a friend’s QR code. Nothing is recorded or uploaded.'
            : 'Camera access is turned off for BallPicker. Enable it in your device settings, or type the friend code manually instead.'}
        </Text>
        {permission.canAskAgain ? (
          <AppButton title="Allow camera" onPress={requestPermission} style={{ marginTop: spacing.lg }} />
        ) : null}
        <AppButton
          title="Enter code manually"
          onPress={() => navigation.navigate('Friends')}
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
            setError('That QR code is not a BallPicker friend code.');
            return;
          }
          handled.current = true;
          navigation.navigate('Friends', { scannedCode: code });
        }}
      />
      <View style={styles.overlay}>
        <Text style={styles.overlayText}>Point the camera at a BallPicker friend QR code.</Text>
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
