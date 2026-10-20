import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { AppButton } from '../AppButton';
import { LineIcon, LineIconName } from '../LineIcon';
import { Sheet } from './Sheet';

interface CardProps {
  code: string | null;
  copied: boolean;
  onCopy: () => void;
  onShowQr: () => void;
}

function IconButton({ icon, color, label, onPress, disabled, styles, testID }: {
  icon: LineIconName;
  color: string;
  label: string;
  onPress: () => void;
  disabled: boolean;
  styles: Styles;
  testID: string;
}) {
  return (
    <TouchableOpacity
      onPress={onPress}
      disabled={disabled}
      style={[styles.iconBtn, disabled && styles.disabled]}
      activeOpacity={0.7}
      accessibilityRole="button"
      accessibilityLabel={label}
      testID={testID}
    >
      <LineIcon name={icon} color={color} size={20} />
    </TouchableOpacity>
  );
}

/** One compact row: the player's own code, with Copy and Show-QR actions. */
export function FriendCodeCard({ code, copied, onCopy, onShowQr }: CardProps) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <View style={styles.card} testID="friends-code-card">
      <View style={styles.text}>
        {/* The label doubles as the copy confirmation, so feedback needs no extra space. */}
        <Text style={[styles.label, copied && styles.labelCopied]} maxFontSizeMultiplier={1.3} accessibilityLiveRegion="polite">
          {copied ? t('common.buttons.copied') : t('friends.list.codeTitle')}
        </Text>
        <Text style={styles.code} selectable numberOfLines={1} adjustsFontSizeToFit maxFontSizeMultiplier={1.3} testID="friends-code">
          {code ?? '········'}
        </Text>
      </View>
      <IconButton
        icon={copied ? 'check' : 'copy'}
        color={copied ? theme.primary : theme.text}
        label={t('friends.list.copyCode')}
        onPress={onCopy}
        disabled={!code}
        styles={styles}
        testID="friends-copy"
      />
      <IconButton
        icon="qr"
        color={theme.text}
        label={t('friends.list.showQr')}
        onPress={onShowQr}
        disabled={!code}
        styles={styles}
        testID="friends-show-qr"
      />
    </View>
  );
}

interface SheetProps {
  visible: boolean;
  code: string | null;
  copied: boolean;
  onCopy: () => void;
  onClose: () => void;
}

/** Full, scannable QR code — only on demand, so the page itself stays compact. */
export function QrCodeSheet({ visible, code, copied, onCopy, onClose }: SheetProps) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <Sheet visible={visible} title={t('friends.list.qrTitle')} onClose={onClose} testID="friends-qr-sheet">
      <View style={styles.qrBody}>
        {code ? (
          <View style={styles.qrWrap} accessible accessibilityRole="image" accessibilityLabel={t('friends.list.qrA11y', { code })}>
            {/* Black on white, quiet zone included: scans reliably from any theme. */}
            <QRCode value={code} size={208} color="#000000" backgroundColor="#ffffff" />
          </View>
        ) : null}
        <Text style={styles.qrCode} selectable>{code ?? ''}</Text>
        <Text style={styles.qrHint}>{t('friends.list.codeHint')}</Text>
        <AppButton
          title={copied ? t('common.buttons.copied') : t('friends.list.copyCode')}
          onPress={onCopy}
          variant="secondary"
          disabled={!code}
          style={styles.qrButton}
        />
      </View>
    </Sheet>
  );
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm,
      backgroundColor: theme.surface, borderRadius: 16, borderWidth: 1, borderColor: theme.border,
      paddingVertical: spacing.sm + 2, paddingLeft: spacing.md, paddingRight: spacing.sm + 2,
    },
    text: { flex: 1 },
    label: { fontSize: 11, fontWeight: '700', letterSpacing: 1, textTransform: 'uppercase', color: theme.textSecondary },
    labelCopied: { color: theme.primary },
    code: { fontSize: 21, fontWeight: '800', letterSpacing: 2.5, color: theme.text, marginTop: 1, fontVariant: ['tabular-nums'] },
    iconBtn: {
      width: 44, height: 44, borderRadius: 12,
      alignItems: 'center', justifyContent: 'center',
      backgroundColor: theme.surfaceElevated, borderWidth: 1, borderColor: theme.border,
    },
    disabled: { opacity: 0.5 },
    qrBody: { alignItems: 'center', gap: spacing.sm, paddingBottom: spacing.xs },
    qrWrap: { backgroundColor: '#ffffff', padding: spacing.md - 4, borderRadius: 16 },
    qrCode: { fontSize: 24, fontWeight: '800', letterSpacing: 3, color: theme.primary, marginTop: spacing.xs },
    qrHint: { fontSize: 13, lineHeight: 18, color: theme.textSecondary, textAlign: 'center', paddingHorizontal: spacing.md },
    qrButton: { alignSelf: 'stretch', marginTop: spacing.sm },
  });
}
