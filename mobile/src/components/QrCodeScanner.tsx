import React, { useRef, useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Screen } from './Screen';
import { AppButton } from './AppButton';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';

/** Outcome of reading one QR payload: a usable value, or the error to show. */
export type QrParseResult = { ok: true; value: string } | { ok: false; error: string };

export interface QrScannerCopy {
  preparing: string;
  permissionTitle: string;
  permissionAsk: string;
  permissionDenied: string;
  allowCamera: string;
  enterManually: string;
  hint: string;
}

interface Props {
  /** Turn the raw QR text into a value (or an error message). */
  parse: (raw: string) => QrParseResult;
  /** Called once with the first valid value. */
  onValue: (value: string) => void;
  /** The "type it instead" escape when the camera is unavailable. */
  onEnterManually: () => void;
  /** Already translated by the calling screen (friend vs club copy). */
  copy: QrScannerCopy;
}

/**
 * Shared QR scanner (friend codes, club codes): camera permission flow,
 * the camera preview and a hint/error overlay. The camera permission is
 * requested here — only when the user opts into scanning — and a scan is
 * handed over exactly once, however often onBarcodeScanned fires.
 */
export function QrCodeScanner({ parse, onValue, onEnterManually, copy }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const [permission, requestPermission] = useCameraPermissions();
  const [error, setError] = useState('');
  // Guards against onBarcodeScanned firing repeatedly for the same code.
  const handled = useRef(false);

  if (!permission) {
    return (
      <Screen padding>
        <Text style={styles.body}>{copy.preparing}</Text>
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen padding>
        <Text style={styles.title}>{copy.permissionTitle}</Text>
        <Text style={styles.body}>{permission.canAskAgain ? copy.permissionAsk : copy.permissionDenied}</Text>
        {permission.canAskAgain ? (
          <AppButton title={copy.allowCamera} onPress={requestPermission} style={{ marginTop: spacing.lg }} />
        ) : null}
        <AppButton title={copy.enterManually} onPress={onEnterManually} variant="secondary" style={{ marginTop: spacing.sm }} />
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
          const parsed = parse(String(data ?? ''));
          if (!parsed.ok) {
            setError(parsed.error);
            return;
          }
          handled.current = true;
          onValue(parsed.value);
        }}
      />
      <View style={styles.overlay}>
        <Text style={styles.overlayText}>{copy.hint}</Text>
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
