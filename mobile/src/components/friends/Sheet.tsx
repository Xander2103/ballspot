import React from 'react';
import {
  Modal, View, Text, StyleSheet, TouchableOpacity, TouchableWithoutFeedback,
  KeyboardAvoidingView, Platform,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { LineIcon } from '../LineIcon';

interface Props {
  visible: boolean;
  title: string;
  onClose: () => void;
  /** While true (a request is in flight) the sheet cannot be dismissed. */
  locked?: boolean;
  children: React.ReactNode;
  testID?: string;
}

/**
 * Bottom sheet used by the Friends screen (QR code, Add a friend): a plain RN
 * Modal, so no new dependency. Rises with the keyboard on iOS, keeps clear of
 * the home indicator, and closes on the backdrop, the ✕ or Android back.
 */
export function Sheet({ visible, title, onClose, locked = false, children, testID }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const insets = useSafeAreaInsets();
  const styles = createStyles(theme);
  const dismiss = locked ? () => {} : onClose;

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={dismiss} statusBarTranslucent>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <TouchableWithoutFeedback onPress={dismiss} accessible={false}>
          <View style={styles.backdrop} />
        </TouchableWithoutFeedback>
        <View
          style={[styles.sheet, { paddingBottom: Math.max(insets.bottom, spacing.md) + spacing.sm }]}
          testID={testID}
          accessibilityViewIsModal
        >
          <View style={styles.grabber} />
          <View style={styles.header}>
            <Text style={styles.title} accessibilityRole="header" maxFontSizeMultiplier={1.4}>{title}</Text>
            <TouchableOpacity
              onPress={dismiss}
              disabled={locked}
              style={styles.close}
              accessibilityRole="button"
              accessibilityLabel={t('common.buttons.close')}
            >
              <LineIcon name="close" color={theme.textSecondary} size={20} />
            </TouchableOpacity>
          </View>
          {children}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    flex: { flex: 1, justifyContent: 'flex-end' },
    backdrop: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: theme.overlay },
    sheet: {
      backgroundColor: theme.surface,
      borderTopLeftRadius: 22, borderTopRightRadius: 22,
      borderWidth: 1, borderBottomWidth: 0, borderColor: theme.border,
      paddingHorizontal: spacing.md + 4, paddingTop: spacing.sm,
    },
    grabber: {
      alignSelf: 'center', width: 36, height: 4, borderRadius: 2,
      backgroundColor: theme.border, marginBottom: spacing.sm,
    },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.sm },
    title: { flex: 1, fontSize: 18, fontWeight: '800', color: theme.text },
    close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', marginRight: -10 },
  });
}
