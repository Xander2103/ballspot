import React from 'react';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { QrCodeScanner, type QrParseResult } from '../components/QrCodeScanner';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'ScanFriendCode'>;

/** Accepts a bare code or a `ballpicker:friend:CODE` payload. */
function parseFriendCode(raw: string): string | null {
  const value = raw.trim().replace(/^ballpicker:friend:/i, '').toUpperCase();
  return /^[A-HJ-NP-Z2-9]{6,12}$/.test(value) ? value : null;
}

export function ScanFriendCodeScreen({ navigation }: Props) {
  const { t } = useI18n();

  const parse = (raw: string): QrParseResult => {
    const code = parseFriendCode(raw);
    return code ? { ok: true, value: code } : { ok: false, error: t('friends.scan.invalidCode') };
  };

  return (
    <QrCodeScanner
      parse={parse}
      onValue={(code) => navigation.navigate('Home', { screen: 'Friends', params: { scannedCode: code } })}
      onEnterManually={() => navigation.navigate('Home', { screen: 'Friends' })}
      copy={{
        preparing: t('friends.scan.preparing'),
        permissionTitle: t('friends.scan.permissionTitle'),
        permissionAsk: t('friends.scan.permissionAsk'),
        permissionDenied: t('friends.scan.permissionDenied'),
        allowCamera: t('friends.scan.allowCamera'),
        enterManually: t('friends.scan.enterManually'),
        hint: t('friends.scan.hint'),
      }}
    />
  );
}
