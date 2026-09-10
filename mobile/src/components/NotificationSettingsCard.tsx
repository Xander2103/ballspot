import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, Switch, TextInput, ActivityIndicator, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n, t as translate } from '../i18n';
import { notifications, isValidReminderTime, type PermissionStatus } from '../services/notifications';
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
  const [perm, setPerm] = useState<PermissionStatus>('undetermined');
  const [timeInput, setTimeInput] = useState('19:00');
  const [timeError, setTimeError] = useState('');
  const [error, setError] = useState('');
  const [savingKey, setSavingKey] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [data, status] = await Promise.all([
        notificationsApi.getSettings(),
        notifications.getPermissionStatus(),
      ]);
      setSettings(data);
      setTimeInput(data.reminder_time || '19:00');
      setPerm(status);
    } catch {
      // Module-level translate: keeps `load` stable so a language change
      // does not re-fetch the settings.
      setError(translate('notifications.settings.loadError'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

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
    const status = await notifications.requestPermission();
    setPerm(status);
    if (status === 'granted') {
      await notifications.registerPushToken();
      await refreshRemindersFromServer();
    }
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
      <PermissionBanner styles={styles} perm={perm} onEnable={enablePermission} />

      {TOGGLES.map((toggle, i) => (
        <View key={toggle.key} style={[styles.row, i > 0 && styles.rowBorder]}>
          <View style={styles.rowText}>
            <Text style={styles.rowLabel}>{t(toggle.labelKey)}</Text>
            <Text style={styles.rowHint}>{t(toggle.hintKey)}</Text>
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
          <Text style={styles.rowLabel}>{t('notifications.settings.reminderTime')}</Text>
          <Text style={styles.rowHint}>{t('notifications.settings.reminderTimeHint')}</Text>
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

function PermissionBanner({
  styles, perm, onEnable,
}: { styles: Styles; perm: PermissionStatus; onEnable: () => void }) {
  const { t } = useI18n();
  if (perm === 'granted') {
    return <Text style={styles.statusOk}>{t('notifications.settings.enabled')}</Text>;
  }
  if (perm === 'unsupported') {
    return <Text style={styles.statusMuted}>{t('notifications.settings.unsupported')}</Text>;
  }
  if (perm === 'denied') {
    return <Text style={styles.statusWarn}>{t('notifications.settings.denied')}</Text>;
  }
  // undetermined
  return (
    <TouchableOpacity style={styles.enableBtn} onPress={onEnable} activeOpacity={0.85}>
      <Text style={styles.enableBtnText}>{t('notifications.settings.enable')}</Text>
    </TouchableOpacity>
  );
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
    statusOk: { fontSize: 13, color: theme.success, marginBottom: spacing.sm, fontWeight: '600' },
    statusWarn: { fontSize: 13, color: theme.warning, marginBottom: spacing.sm },
    statusMuted: { fontSize: 13, color: theme.textMuted, marginBottom: spacing.sm },
    enableBtn: {
      backgroundColor: theme.primary,
      borderRadius: 10,
      paddingVertical: spacing.sm,
      alignItems: 'center',
      marginBottom: spacing.sm,
    },
    enableBtnText: { color: theme.onPrimary, fontWeight: '700', fontSize: 14 },
    errorText: { fontSize: 13, color: theme.danger, marginTop: spacing.xs },
    retry: { fontSize: 14, color: theme.primary, fontWeight: '600', marginTop: spacing.xs },
  });
}
