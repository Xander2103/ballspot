import React, { useEffect, useRef, useState, useCallback } from 'react';
import {
  AppState, View, Text, StyleSheet, Switch, TextInput, ActivityIndicator, TouchableOpacity, Linking,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n, t as translate } from '../i18n';
import { notifications, isValidReminderTime } from '../services/notifications';
import { canDeliver, reminderDelivery, type DevicePermission } from '../utils/notificationPermission';
import { refreshRemindersFromServer } from '../services/reminderScheduler';
import {
  notificationsApi,
  type NotificationSettings,
  type NotificationSettingsUpdate,
} from '../api/notificationsApi';

type ToggleKey =
  | 'daily_reminder_enabled'
  | 'tournament_reminder_enabled'
  | 'admin_notifications_enabled';

/** Labels resolve via t() inside the component (locale-aware). */
/** Same limit as the rest of the Settings screen. */
const MAX_FONT_SCALE = 1.4;

const TOGGLES: { key: ToggleKey; labelKey: string; hintKey: string }[] = [
  { key: 'daily_reminder_enabled', labelKey: 'notifications.settings.daily.label', hintKey: 'notifications.settings.daily.hint' },
  { key: 'tournament_reminder_enabled', labelKey: 'notifications.settings.tournament.label', hintKey: 'notifications.settings.tournament.hint' },
  { key: 'admin_notifications_enabled', labelKey: 'notifications.settings.announcements.label', hintKey: 'notifications.settings.announcements.hint' },
];

export function NotificationSettingsCard({ flat = false }: { flat?: boolean } = {}) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);
  const cardStyle = [styles.card, flat && styles.flat];

  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState<NotificationSettings | null>(null);
  const [perm, setPerm] = useState<DevicePermission | null>(null);
  const lastPerm = useRef<DevicePermission | null>(null);
  const [timeInput, setTimeInput] = useState('19:00');
  const [timeError, setTimeError] = useState('');
  const [error, setError] = useState('');
  const [savingKey, setSavingKey] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await notificationsApi.getSettings();
      setSettings(data);
      setTimeInput(data.reminder_time || '19:00');
    } catch {
      // Module-level translate: keeps `load` stable so a language change
      // does not re-fetch the settings.
      setError(translate('notifications.settings.loadError'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // The OS is the source of truth and can change while this screen is open:
  // read it on every focus and every return from the background (Settings app).
  const refreshPermission = useCallback(async () => {
    const status = await notifications.reconcile();
    const previous = lastPerm.current;
    lastPerm.current = status;
    setPerm(status);
    // Just allowed: the reminders Home could not schedule can be scheduled now.
    if (previous !== null && !canDeliver(previous) && canDeliver(status)) {
      await refreshRemindersFromServer();
    }
  }, []);

  useFocusEffect(useCallback(() => { refreshPermission(); }, [refreshPermission]));

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') refreshPermission();
    });
    return () => sub.remove();
  }, [refreshPermission]);

  const persist = useCallback(async (patch: NotificationSettingsUpdate, key: string) => {
    if (!settings) return;
    const previous = settings;
    setError('');
    setSavingKey(key);
    // Optimistic update.
    setSettings({ ...settings, ...patch });
    try {
      const updated = await notificationsApi.updateSettings(patch);
      setSettings(updated);
      // Settings changed — reconcile the on-device schedule.
      await refreshRemindersFromServer();
    } catch {
      setSettings(previous); // revert
      setError(translate('notifications.settings.saveError'));
    } finally {
      setSavingKey(null);
    }
  }, [settings]);

  async function enablePermission() {
    await notifications.requestPermission();
    await refreshPermission();
  }

  function openDeviceSettings() {
    Linking.openSettings().catch(() => {});
  }

  function commitTime() {
    const value = timeInput.trim();
    if (!isValidReminderTime(value)) {
      setTimeError(t('notifications.settings.timeInvalid'));
      return;
    }
    setTimeError('');
    if (settings && value !== settings.reminder_time) {
      persist({ reminder_time: value }, 'reminder_time');
    }
  }

  if (loading) {
    return (
      <View style={[...cardStyle, styles.center]}>
        <ActivityIndicator color={theme.primary} />
      </View>
    );
  }

  if (!settings) {
    return (
      <View style={cardStyle}>
        <Text style={styles.errorText}>{error || t('notifications.settings.unavailable')}</Text>
        <TouchableOpacity onPress={load}><Text style={styles.retry}>{t('common.buttons.retry')}</Text></TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={cardStyle}>
      <DeviceStatus styles={styles} perm={perm} onEnable={enablePermission} onOpenSettings={openDeviceSettings} />

      {TOGGLES.map((toggle, i) => (
        <View key={toggle.key} style={[styles.row, i > 0 && styles.rowBorder]}>
          <View style={styles.rowText}>
            <Text style={styles.rowLabel} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t(toggle.labelKey)}</Text>
            <Text style={styles.rowHint} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t(toggle.hintKey)}</Text>
            <DeliveryNote styles={styles} preference={settings[toggle.key]} perm={perm} />
          </View>
          <Switch
            value={settings[toggle.key]}
            disabled={savingKey === toggle.key}
            onValueChange={(v) => persist({ [toggle.key]: v } as NotificationSettingsUpdate, toggle.key)}
            trackColor={{ true: theme.primary, false: theme.border }}
            thumbColor={theme.surface}
          />
        </View>
      ))}

      <View style={[styles.row, styles.rowBorder]}>
        <View style={styles.rowText}>
          <Text style={styles.rowLabel} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.reminderTime')}</Text>
          <Text style={styles.rowHint} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.reminderTimeHint')}</Text>
        </View>
        <TextInput
          style={styles.timeInput}
          value={timeInput}
          onChangeText={setTimeInput}
          onBlur={commitTime}
          onSubmitEditing={commitTime}
          placeholder="19:00"
          placeholderTextColor={theme.textMuted}
          keyboardType="numbers-and-punctuation"
          maxLength={5}
          returnKeyType="done"
        />
      </View>
      {timeError ? <Text style={styles.errorText}>{timeError}</Text> : null}
      {error ? <Text style={styles.errorText}>{error}</Text> : null}
    </View>
  );
}

/** What the device allows, separate from the preference toggles below it. */
function DeviceStatus({
  styles, perm, onEnable, onOpenSettings,
}: { styles: Styles; perm: DevicePermission | null; onEnable: () => void; onOpenSettings: () => void }) {
  const { t } = useI18n();
  if (perm === null) return null;
  if (perm === 'unsupported') {
    return <Text style={[styles.deviceHint, styles.deviceBlock]} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.unsupported')}</Text>;
  }

  const toneStyle = canDeliver(perm) ? styles.statusOk : perm === 'denied' ? styles.statusWarn : styles.statusMuted;
  const action = perm === 'notDetermined'
    ? { label: t('notifications.settings.enable'), onPress: onEnable }
    : perm === 'denied' || perm === 'provisional'
      ? { label: t('notifications.settings.openSettings'), onPress: onOpenSettings }
      : null;

  return (
    <View style={styles.deviceBlock} testID={`notif-device-${perm}`}>
      <Text style={styles.deviceLabel} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.device.label')}</Text>
      <Text style={[styles.deviceStatus, toneStyle]} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t(`notifications.settings.device.${perm}`)}</Text>
      <Text style={styles.deviceHint} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t(`notifications.settings.device.${perm}Hint`)}</Text>
      {action ? (
        <TouchableOpacity style={styles.enableBtn} onPress={action.onPress} activeOpacity={0.85} accessibilityRole="button">
          <Text style={styles.enableBtnText} maxFontSizeMultiplier={MAX_FONT_SCALE}>{action.label}</Text>
        </TouchableOpacity>
      ) : null}
    </View>
  );
}

/** Shown only when a toggle is on but the device will not deliver it. */
function DeliveryNote({
  styles, preference, perm,
}: { styles: Styles; preference: boolean; perm: DevicePermission | null }) {
  const { t } = useI18n();
  if (perm === null) return null;
  const delivery = reminderDelivery(preference, perm);
  if (delivery === 'blocked') return <Text style={styles.noteWarn} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.delivery.blocked')}</Text>;
  if (delivery === 'notEnabled') return <Text style={styles.noteMuted} maxFontSizeMultiplier={MAX_FONT_SCALE}>{t('notifications.settings.delivery.notEnabled')}</Text>;
  return null;
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      backgroundColor: theme.surface,
      borderRadius: 14,
      borderWidth: 1,
      borderColor: theme.border,
      padding: spacing.md,
    },
    // Rendered inside an already-carded container (e.g. a collapsible section).
    flat: { backgroundColor: 'transparent', borderWidth: 0, borderRadius: 0, padding: 0 },
    center: { alignItems: 'center', justifyContent: 'center', minHeight: 80 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      paddingVertical: spacing.sm,
    },
    rowBorder: { borderTopWidth: 1, borderTopColor: theme.border },
    rowText: { flex: 1, paddingRight: spacing.md },
    rowLabel: { fontSize: 15, fontWeight: '600', color: theme.text },
    rowHint: { fontSize: 12, color: theme.textMuted, marginTop: 2 },
    timeInput: {
      minWidth: 72,
      textAlign: 'center',
      fontSize: 16,
      fontWeight: '700',
      color: theme.text,
      backgroundColor: theme.surfaceElevated,
      borderWidth: 1,
      borderColor: theme.border,
      borderRadius: 8,
      paddingVertical: spacing.xs,
      paddingHorizontal: spacing.sm,
    },
    deviceBlock: {
      paddingBottom: spacing.md,
      marginBottom: spacing.xs,
      borderBottomWidth: 1,
      borderBottomColor: theme.border,
    },
    deviceLabel: { fontSize: 12, fontWeight: '600', color: theme.textSecondary },
    deviceStatus: { fontSize: 15, fontWeight: '700', marginTop: 2 },
    deviceHint: { fontSize: 13, lineHeight: 18, color: theme.textSecondary, marginTop: 2 },
    statusOk: { color: theme.success },
    statusWarn: { color: theme.warning },
    statusMuted: { color: theme.text },
    noteWarn: { fontSize: 12, color: theme.warning, marginTop: 2, fontWeight: '600' },
    noteMuted: { fontSize: 12, color: theme.textSecondary, marginTop: 2, fontWeight: '600' },
    enableBtn: {
      backgroundColor: theme.primary,
      borderRadius: 10,
      paddingVertical: spacing.sm,
      paddingHorizontal: spacing.md,
      alignItems: 'center',
      marginTop: spacing.sm,
    },
    enableBtnText: { color: theme.onPrimary, fontWeight: '700', fontSize: 14, textAlign: 'center' },
    errorText: { fontSize: 13, color: theme.danger, marginTop: spacing.xs },
    retry: { fontSize: 14, color: theme.primary, fontWeight: '600', marginTop: spacing.xs },
  });
}
