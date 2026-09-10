import React from 'react';
import { Text, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { useI18n } from '../i18n';

interface Props {
  label: string;
  onPress: () => void;
}

/** Header-left "leave this flow" button used by the game-mode screens. */
export function HeaderExitButton({ label, onPress }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  return (
    <TouchableOpacity
      onPress={onPress}
      hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
      accessibilityRole="button"
      accessibilityLabel={t('nav.backTo', { label })}
    >
      <Text style={{ color: theme.primary, fontSize: 16, fontWeight: '700' }}>‹ {label}</Text>
    </TouchableOpacity>
  );
}
