import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { AppButton } from '../AppButton';
import { AppInput } from '../AppInput';
import { LineIcon } from '../LineIcon';
import { Sheet } from './Sheet';

interface Props {
  visible: boolean;
  value: string;
  onChange: (value: string) => void;
  sending: boolean;
  error: string;
  notice: string;
  onSend: () => void;
  onScan: () => void;
  onClose: () => void;
}

/** Friend code entry. Validation and the request itself live in FriendsScreen (utils/friendsModel). */
export function AddFriendSheet({ visible, value, onChange, sending, error, notice, onSend, onScan, onClose }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <Sheet visible={visible} title={t('friends.list.addTitle')} onClose={onClose} locked={sending} testID="friends-add-sheet">
      <Text style={styles.hint}>{t('friends.list.addHint')}</Text>
      <AppInput
        value={value}
        onChangeText={(text) => onChange(text.toUpperCase())}
        autoCapitalize="characters"
        autoCorrect={false}
        autoComplete="off"
        autoFocus
        placeholder="ABCD2345"
        maxLength={16}
        returnKeyType="send"
        onSubmitEditing={onSend}
        editable={!sending}
        accessibilityLabel={t('friends.list.codeLabel')}
        style={styles.input}
        testID="friends-add-input"
      />
      <View style={styles.feedback} accessibilityLiveRegion="polite">
        {error ? <Text style={styles.error} testID="friends-add-error">{error}</Text> : null}
        {!error && notice ? <Text style={styles.notice} testID="friends-add-notice">{notice}</Text> : null}
      </View>
      <AppButton title={t('friends.list.sendRequest')} onPress={onSend} loading={sending} disabled={!value.trim() || sending} />
      <TouchableOpacity
        onPress={onScan}
        disabled={sending}
        style={styles.scan}
        accessibilityRole="button"
        testID="friends-add-scan"
      >
        <LineIcon name="scan" color={theme.primary} size={18} />
        <Text style={styles.scanText}>{t('friends.list.scanQr')}</Text>
      </TouchableOpacity>
    </Sheet>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    hint: { fontSize: 13, lineHeight: 18, color: theme.textSecondary, marginBottom: spacing.md - 4 },
    input: { fontSize: 18, fontWeight: '700', letterSpacing: 2 },
    // Fixed slot: an error or the success line never makes the button jump.
    feedback: { minHeight: 20, marginTop: -spacing.sm, marginBottom: spacing.sm },
    error: { color: theme.danger, fontSize: 13 },
    notice: { color: theme.success, fontSize: 13, fontWeight: '600' },
    scan: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, minHeight: 44, marginTop: spacing.xs },
    scanText: { fontSize: 14, fontWeight: '700', color: theme.primary },
  });
}
