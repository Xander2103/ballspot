import React from 'react';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { QrCodeScanner, type QrParseResult } from '../components/QrCodeScanner';
import { isFriendQrPayload, parseClubCode } from '../utils/clubCode';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'ScanClubCode'>;

/**
 * Scans a club QR (`ballpicker:club:CODE` or a bare code) and hands the code
 * to JoinClubScreen, which joins right away. Same camera flow as the friend
 * scanner (components/QrCodeScanner).
 */
export function ScanClubCodeScreen({ navigation }: Props) {
  const { t } = useI18n();

  const parse = (raw: string): QrParseResult => {
    const code = parseClubCode(raw);
    if (code) return { ok: true, value: code };
    return { ok: false, error: isFriendQrPayload(raw) ? t('clubs.scan.friendCode') : t('clubs.scan.invalidCode') };
  };

  return (
    <QrCodeScanner
      parse={parse}
      // popTo: back to the Join screen underneath (added when the scan
      // started from the Clubs tab's empty state).
      onValue={(code) => navigation.popTo('JoinClub', { scannedCode: code })}
      onEnterManually={() => navigation.popTo('JoinClub')}
      copy={{
        preparing: t('clubs.scan.preparing'),
        permissionTitle: t('clubs.scan.permissionTitle'),
        permissionAsk: t('clubs.scan.permissionAsk'),
        permissionDenied: t('clubs.scan.permissionDenied'),
        allowCamera: t('clubs.scan.allowCamera'),
        enterManually: t('clubs.scan.enterManually'),
        hint: t('clubs.scan.hint'),
      }}
    />
  );
}
