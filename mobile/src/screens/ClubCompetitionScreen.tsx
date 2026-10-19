import React from 'react';
import { Text, StyleSheet } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { EmptyState } from '../components/EmptyState';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import { useI18n } from '../i18n';

type Props = NativeStackScreenProps<RootStackParamList, 'ClubCompetition'>;

/**
 * Placeholder for the competition screen (rules, per-pack attempts, standings).
 * The competitions follow-up replaces this body; the route and its params are
 * already final so club detail links keep working.
 */
export function ClubCompetitionScreen({ navigation, route }: Props) {
  const { name } = route.params;
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <Screen padding>
      {name ? <Text style={styles.title}>{name}</Text> : null}
      <EmptyState
        icon="🏆"
        title={t('clubs.competitions.comingSoonTitle')}
        message={t('clubs.competitions.comingSoonMessage')}
        actions={[{ label: t('clubs.competitions.backToClub'), onPress: () => navigation.goBack() }]}
      />
    </Screen>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    title: { fontSize: 22, fontWeight: '800', color: theme.text, textAlign: 'center', marginTop: spacing.lg },
  });
}
