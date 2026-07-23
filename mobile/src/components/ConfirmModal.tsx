import React from 'react';
import { View, Text, StyleSheet, Modal, TouchableWithoutFeedback } from 'react-native';
import { AppButton } from './AppButton';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

interface Props {
  visible: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  onConfirm: () => void;
  onCancel: () => void;
  destructive?: boolean;
  /** While true, the confirm action is in flight: buttons disable, confirm shows a spinner, and the modal cannot be dismissed. */
  loading?: boolean;
  /** Error to show inside the modal (e.g. the confirm action failed) so it is not hidden behind the overlay. */
  errorText?: string;
}

export function ConfirmModal({
  visible,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  onConfirm,
  onCancel,
  destructive = false,
  loading = false,
  errorText,
}: Props) {
  // Never dismiss the dialog while the confirm action is running.
  const dismiss = loading ? () => {} : onCancel;
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={dismiss}>
      <TouchableWithoutFeedback onPress={dismiss}>
        <View style={styles.overlay}>
          <TouchableWithoutFeedback>
            <View style={styles.dialog}>
              <Text style={styles.title}>{title}</Text>
              <Text style={styles.message}>{message}</Text>
              {errorText ? <Text style={styles.error}>{errorText}</Text> : null}
              <View style={styles.buttons}>
                <AppButton
                  title={cancelLabel}
                  onPress={onCancel}
                  variant="secondary"
                  disabled={loading}
                  style={styles.btn}
                />
                <AppButton
                  title={confirmLabel}
                  onPress={onConfirm}
                  variant={destructive ? 'danger' : 'primary'}
                  loading={loading}
                  style={styles.btn}
                />
              </View>
            </View>
          </TouchableWithoutFeedback>
        </View>
      </TouchableWithoutFeedback>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: colors.overlay,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  dialog: {
    backgroundColor: colors.surface,
    borderRadius: 18,
    padding: spacing.xl,
    width: '100%',
    maxWidth: 360,
    borderWidth: 1,
    borderColor: colors.border,
  },
  title: {
    fontSize: 18,
    fontWeight: '700',
    color: colors.text,
    marginBottom: spacing.sm,
  },
  message: {
    fontSize: 14,
    color: colors.textSecondary,
    marginBottom: spacing.lg,
    lineHeight: 20,
  },
  error: {
    fontSize: 13,
    color: colors.error,
    marginTop: -spacing.sm,
    marginBottom: spacing.md,
  },
  buttons: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  btn: {
    flex: 1,
  },
});
