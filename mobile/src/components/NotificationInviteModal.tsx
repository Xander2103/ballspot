import React from 'react';
import { Modal, ScrollView, StyleSheet, Text, TouchableOpacity, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { AppButton } from './AppButton';
import { LineIcon } from './LineIcon';
import { useI18n } from '../i18n';
import { spacing } from '../theme/spacing';
import type { ThemeTokens } from '../theme/themes';
import { useTheme } from '../theme/useTheme';

interface Props {
  /** null = hidden. */
  variant: 'requestPermission' | 'openSettings' | null;
  busy: boolean;
  onAccept: () => void;
  onLater: () => void;
}

const MAX_WIDTH = 420;
const SCREEN_MARGIN = spacing.md;
/**
 * Large text sizes stay usable up to here. Beyond it, words wider than the
 * button break mid-word and the buttons leave no room for the explanation.
 */
const MAX_FONT_SCALE = 1.5;

/**
 * The buttons stay outside the scroll area, so they remain reachable however
 * large the text gets; only the explanation scrolls, with a visible scrollbar.
 */
export function NotificationInviteModal({ variant, busy, onAccept, onLater }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const styles = createStyles(theme);

  const blocked = variant === 'openSettings';
  const maxHeight = height - insets.top - insets.bottom - SCREEN_MARGIN * 2;
  const later = busy ? () => {} : onLater;

  return (
    <Modal
      visible={variant !== null}
      transparent
      animationType="fade"
      onRequestClose={later}
      statusBarTranslucent
      navigationBarTranslucent
    >
      <View
        style={[
          styles.overlay,
          {
            paddingTop: insets.top + SCREEN_MARGIN,
            paddingBottom: insets.bottom + SCREEN_MARGIN,
            paddingLeft: insets.left + SCREEN_MARGIN,
            paddingRight: insets.right + SCREEN_MARGIN,
          },
        ]}
      >
        <View testID="notif-invite" style={[styles.card, { maxHeight }]} accessibilityViewIsModal>
          <ScrollView
            style={styles.scroll}
            contentContainerStyle={styles.content}
            bounces={false}
            persistentScrollbar
          >
            <View style={styles.iconTile}>
              <LineIcon name="bell" color={theme.primary} size={28} />
            </View>
            <Text style={styles.title} maxFontSizeMultiplier={MAX_FONT_SCALE} accessibilityRole="header">
              {t(blocked ? 'home.notifPrompt.blocked.title' : 'home.notifPrompt.title')}
            </Text>
            <Text style={styles.eyebrow} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('home.notifPrompt.eyebrow')}</Text>
            <Text style={styles.message} maxFontSizeMultiplier={MAX_FONT_SCALE}>
              {t(blocked ? 'home.notifPrompt.blocked.message' : 'home.notifPrompt.message')}
            </Text>
            {blocked ? null : (
              <>
                <Text style={styles.support} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('home.notifPrompt.support')}</Text>
                <View style={styles.highlight}>
                  <LineIcon name="calendar" color={theme.primary} size={20} />
                  <Text style={styles.highlightText} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('home.notifPrompt.highlight')}</Text>
                </View>
              </>
            )}
          </ScrollView>

          <View style={styles.actions}>
            <AppButton
              title={t(blocked ? 'home.notifPrompt.blocked.confirm' : 'home.notifPrompt.confirm')}
              onPress={onAccept}
              loading={busy}
              maxFontSizeMultiplier={MAX_FONT_SCALE}
            />
            <TouchableOpacity
              testID="notif-invite-later"
              style={styles.later}
              onPress={later}
              disabled={busy}
              accessibilityRole="button"
              activeOpacity={0.7}
            >
              <Text style={styles.laterText} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('home.notifPrompt.cancel')}</Text>
            </TouchableOpacity>
          </View>
        </View>
      </View>
    </Modal>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    overlay: {
      flex: 1,
      backgroundColor: theme.overlay,
      alignItems: 'center',
      justifyContent: 'center',
    },
    card: {
      width: '100%',
      maxWidth: MAX_WIDTH,
      backgroundColor: theme.surface,
      borderRadius: 20,
      borderWidth: 1,
      borderColor: theme.border,
      overflow: 'hidden',
    },
    scroll: { flexGrow: 0, flexShrink: 1 },
    content: {
      alignItems: 'center',
      paddingTop: spacing.lg,
      paddingHorizontal: spacing.lg,
      paddingBottom: spacing.md,
    },
    iconTile: {
      width: 56,
      height: 56,
      borderRadius: 16,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: theme.surfaceElevated,
      marginBottom: spacing.md,
    },
    title: {
      fontSize: 22,
      lineHeight: 28,
      fontWeight: '800',
      color: theme.text,
      textAlign: 'center',
    },
    eyebrow: {
      fontSize: 12,
      fontWeight: '600',
      letterSpacing: 0.6,
      textTransform: 'uppercase',
      color: theme.textSecondary,
      textAlign: 'center',
      marginTop: spacing.xs,
    },
    message: {
      fontSize: 15,
      lineHeight: 22,
      color: theme.text,
      textAlign: 'center',
      marginTop: spacing.md,
    },
    support: {
      fontSize: 14,
      lineHeight: 20,
      color: theme.textSecondary,
      textAlign: 'center',
      marginTop: spacing.sm,
    },
    highlight: {
      alignSelf: 'stretch',
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: spacing.sm,
      backgroundColor: theme.surfaceElevated,
      borderRadius: 12,
      paddingVertical: spacing.sm + 2,
      paddingHorizontal: spacing.md,
      marginTop: spacing.md,
    },
    highlightText: {
      flexShrink: 1,
      fontSize: 13,
      lineHeight: 18,
      fontWeight: '500',
      color: theme.text,
    },
    actions: {
      paddingHorizontal: spacing.lg,
      paddingTop: spacing.xs,
      paddingBottom: spacing.sm,
    },
    later: {
      minHeight: 44,
      alignItems: 'center',
      justifyContent: 'center',
      paddingVertical: spacing.sm,
      marginTop: spacing.xs,
    },
    laterText: {
      fontSize: 15,
      fontWeight: '600',
      color: theme.textSecondary,
      textAlign: 'center',
    },
  });
}
