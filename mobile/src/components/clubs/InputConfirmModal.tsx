import React from 'react';
import { View, Text, StyleSheet, Modal, TouchableWithoutFeedback, TextInput, KeyboardAvoidingView, Platform } from 'react-native';
import { AppButton } from '../AppButton';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';

interface Props {
  visible: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  destructive?: boolean;
  loading?: boolean;
  errorText?: string;
  /** Optional free-text field (e.g. "reason"). */
  input?: { label: string; value: string; onChangeText: (v: string) => void; placeholder?: string; maxLength?: number };
}

/** ConfirmModal with an optional text field (remove / ban with a reason). Theme-aware. */
export function InputConfirmModal({
  visible, title, message, confirmLabel, cancelLabel, onConfirm, onCancel, destructive = false, loading = false, errorText, input,
}: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);
  const dismiss = loading ? () => {} : onCancel;
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={dismiss}>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <TouchableWithoutFeedback onPress={dismiss}>
          <View style={styles.overlay}>
            <TouchableWithoutFeedback>
              <View style={styles.dialog}>
                <Text style={styles.title}>{title}</Text>
                <Text style={styles.message}>{message}</Text>
                {input ? (
                  <View style={styles.inputWrap}>
                    <Text style={styles.inputLabel}>{input.label}</Text>
                    <TextInput
                      value={input.value}
                      onChangeText={input.onChangeText}
                      placeholder={input.placeholder}
                      placeholderTextColor={theme.textMuted}
                      maxLength={input.maxLength}
                      multiline
                      style={styles.input}
                      editable={!loading}
                    />
                  </View>
                ) : null}
                {errorText ? <Text style={styles.error}>{errorText}</Text> : null}
                <View style={styles.buttons}>
                  <AppButton title={cancelLabel} onPress={onCancel} variant="secondary" disabled={loading} style={styles.btn} />
                  <AppButton title={confirmLabel} onPress={onConfirm} variant={destructive ? 'danger' : 'primary'} loading={loading} style={styles.btn} />
                </View>
              </View>
            </TouchableWithoutFeedback>
          </View>
        </TouchableWithoutFeedback>
      </KeyboardAvoidingView>
    </Modal>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    flex: { flex: 1 },
    overlay: { flex: 1, backgroundColor: theme.overlay, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
    dialog: {
      backgroundColor: theme.surface, borderRadius: 18, padding: spacing.xl, width: '100%', maxWidth: 380,
      borderWidth: 1, borderColor: theme.border,
    },
    title: { fontSize: 18, fontWeight: '700', color: theme.text, marginBottom: spacing.sm },
    message: { fontSize: 14, color: theme.textSecondary, marginBottom: spacing.md, lineHeight: 20 },
    inputWrap: { marginBottom: spacing.md },
    inputLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary, marginBottom: spacing.xs },
    input: {
      minHeight: 72, maxHeight: 140, borderRadius: 10, borderWidth: 1, borderColor: theme.border,
      backgroundColor: theme.surfaceElevated, color: theme.text, padding: spacing.sm, fontSize: 14, textAlignVertical: 'top',
    },
    error: { fontSize: 13, color: theme.danger, marginBottom: spacing.md },
    buttons: { flexDirection: 'row', gap: spacing.sm },
    btn: { flex: 1 },
  });
}
