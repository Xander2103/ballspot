import React, { useRef, useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, TextInput } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { EmptyState } from '../components/EmptyState';
import { clubApi } from '../api/clubApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';
import type { ReportReason } from '../types/club';
import { buildReportBody, isReportRateLimited, REPORT_DETAILS_MAX, REPORT_REASONS } from '../utils/clubAdmin';
import { getClubErrorMessage } from '../utils/clubErrors';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubReport'>;

/**
 * Report a club, a competition or a member to BallPicker (any member):
 * one of five reasons + optional details (≤ 500), a confirmation, and calm
 * copy when the hourly report limit (429) is reached.
 */
export function ClubReportScreen({ navigation, route }: Props) {
  const { clubId, subjectType, subjectId, subjectLabel } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  const [reason, setReason] = useState<ReportReason | null>(null);
  const [details, setDetails] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const inFlight = useRef(false);

  const title = subjectType === 'member'
    ? t('clubs.report.titleMember', { name: subjectLabel ?? '' })
    : subjectType === 'competition'
      ? t('clubs.report.titleCompetition')
      : t('clubs.report.titleClub');

  async function handleSend() {
    if (inFlight.current) return;
    const built = buildReportBody({ type: subjectType, id: subjectId ?? null }, reason, details);
    if (!built.ok) { setError(t(built.errorKey, { max: REPORT_DETAILS_MAX })); return; }
    inFlight.current = true;
    setSending(true);
    setError('');
    try {
      await clubApi.report(clubId, built.body);
      setSent(true);
    } catch (e: unknown) {
      setError(isReportRateLimited(e) ? t('clubs.report.rateLimited') : getClubErrorMessage(e, t('clubs.report.failed')));
    } finally {
      inFlight.current = false;
      setSending(false);
    }
  }

  if (sent) {
    return (
      <Screen padding>
        <EmptyState
          icon="✅"
          title={t('clubs.report.sentTitle')}
          message={t('clubs.report.sentMessage')}
          actions={[{ label: t('common.buttons.done'), onPress: () => navigation.goBack() }]}
        />
      </Screen>
    );
  }

  return (
    <Screen scroll padding>
      <Text style={styles.title}>{title}</Text>
      {subjectType !== 'member' && subjectLabel ? <Text style={styles.subject}>{subjectLabel}</Text> : null}
      <Text style={styles.intro}>{t('clubs.report.intro')}</Text>

      <Text style={styles.sectionLabel}>{t('clubs.report.reasonTitle')}</Text>
      <View style={styles.reasons} accessibilityRole="radiogroup">
        {REPORT_REASONS.map((r) => {
          const selected = reason === r;
          return (
            <TouchableOpacity
              key={r}
              style={[styles.reason, selected && styles.reasonSelected]}
              onPress={() => { setReason(r); setError(''); }}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              activeOpacity={0.85}
            >
              <Text style={[styles.radio, selected && styles.radioSelected]}>{selected ? '◉' : '○'}</Text>
              <Text style={styles.reasonText}>{t(`clubs.report.reasons.${r}`)}</Text>
            </TouchableOpacity>
          );
        })}
      </View>

      <Text style={styles.sectionLabel}>{t('clubs.report.detailsLabel')}</Text>
      <TextInput
        value={details}
        onChangeText={(v) => { setDetails(v); if (error) setError(''); }}
        placeholder={t('clubs.report.detailsPlaceholder')}
        placeholderTextColor={theme.textMuted}
        multiline
        maxLength={REPORT_DETAILS_MAX}
        style={styles.details}
        accessibilityLabel={t('clubs.report.detailsLabel')}
      />
      <Text style={styles.counter}>{t('clubs.report.counter', { count: details.length, max: REPORT_DETAILS_MAX })}</Text>

      {error ? <Text style={styles.error}>{error}</Text> : null}
      <AppButton title={t('clubs.report.submit')} onPress={() => { void handleSend(); }} loading={sending} disabled={!reason} style={styles.submit} />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    title: { fontSize: 22, fontWeight: '800', color: theme.text },
    subject: { fontSize: 15, color: theme.textSecondary, marginTop: 2 },
    intro: { fontSize: 13, color: theme.textSecondary, lineHeight: 19, marginTop: spacing.sm },
    sectionLabel: { fontSize: 13, fontWeight: '700', color: theme.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginTop: spacing.lg, marginBottom: spacing.sm },
    reasons: { gap: spacing.sm },
    reason: {
      flexDirection: 'row', alignItems: 'center', gap: spacing.sm, padding: spacing.md, borderRadius: 12,
      borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surface,
    },
    reasonSelected: { borderColor: theme.primary, backgroundColor: theme.surfaceElevated },
    radio: { fontSize: 18, color: theme.textMuted },
    radioSelected: { color: theme.primary },
    reasonText: { flex: 1, fontSize: 15, color: theme.text },
    details: {
      minHeight: 110, borderRadius: 12, borderWidth: 1, borderColor: theme.border, backgroundColor: theme.surfaceElevated,
      color: theme.text, padding: spacing.md, fontSize: 15, textAlignVertical: 'top',
    },
    counter: { fontSize: 12, color: theme.textMuted, textAlign: 'right', marginTop: spacing.xs },
    error: { fontSize: 13, color: theme.danger, marginTop: spacing.md },
    submit: { marginTop: spacing.lg },
  });
}
