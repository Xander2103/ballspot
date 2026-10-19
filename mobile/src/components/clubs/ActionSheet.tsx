import React from 'react';
import { View, Text, StyleSheet, Modal, TouchableOpacity, TouchableWithoutFeedback, Platform } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';

export interface SheetAction {
  key: string;
  label: string;
  onPress: () => void;
  destructive?: boolean;
}

interface Props {
  visible: boolean;
  title?: string;
  actions: SheetAction[];
  cancelLabel: string;
  onClose: () => void;
}

/**
 * iOS cannot present a second Modal (e.g. the leave ConfirmModal) while this
 * one is still fading out, so the chosen action runs after the fade.
 */
const IOS_DISMISS_DELAY_MS = 350;

/** Bottom sheet with a short list of actions (the club "⋯" menu). Theme-aware, no extra dependency. */
export function ActionSheet({ visible, title, actions, cancelLabel, onClose }: Props) {
  const { theme } = useTheme();
  const insets = useSafeAreaInsets();
  const styles = createStyles(theme);
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <TouchableWithoutFeedback onPress={onClose}>
        <View style={styles.overlay}>
          <TouchableWithoutFeedback>
            <View style={[styles.sheet, { paddingBottom: Math.max(insets.bottom, spacing.md) }]}>
              {title ? <Text style={styles.title}>{title}</Text> : null}
              {actions.map((a) => (
                <TouchableOpacity
                  key={a.key}
                  style={styles.action}
                  onPress={() => {
                    onClose();
                    if (Platform.OS === 'ios') setTimeout(a.onPress, IOS_DISMISS_DELAY_MS);
                    else a.onPress();
                  }}
                  accessibilityRole="button"
                >
                  <Text style={[styles.actionText, a.destructive && { color: theme.danger }]}>{a.label}</Text>
                </TouchableOpacity>
              ))}
              <TouchableOpacity style={[styles.action, styles.cancel]} onPress={onClose} accessibilityRole="button">
                <Text style={styles.cancelText}>{cancelLabel}</Text>
              </TouchableOpacity>
            </View>
          </TouchableWithoutFeedback>
        </View>
      </TouchableWithoutFeedback>
    </Modal>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    overlay: { flex: 1, backgroundColor: theme.overlay, justifyContent: 'flex-end' },
    sheet: {
      backgroundColor: theme.surface, borderTopLeftRadius: 18, borderTopRightRadius: 18,
      paddingHorizontal: spacing.md, paddingTop: spacing.md, borderWidth: 1, borderColor: theme.border,
    },
    title: { fontSize: 13, fontWeight: '700', color: theme.textMuted, textAlign: 'center', marginBottom: spacing.sm },
    action: { paddingVertical: spacing.md, borderTopWidth: 1, borderTopColor: theme.border },
    actionText: { fontSize: 16, fontWeight: '600', color: theme.text, textAlign: 'center' },
    cancel: { marginTop: spacing.xs },
    cancelText: { fontSize: 16, fontWeight: '700', color: theme.textSecondary, textAlign: 'center' },
  });
}
