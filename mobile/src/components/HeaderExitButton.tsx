import React from 'react';
import { Text, TouchableOpacity } from 'react-native';
import { useTheme } from '../theme/useTheme';

interface Props {
  label: string;
  onPress: () => void;
}

/** Header-left "leave this flow" button used by the game-mode screens. */
export function HeaderExitButton({ label, onPress }: Props) {
  const { theme } = useTheme();
  return (
    <TouchableOpacity
      onPress={onPress}
      hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
      accessibilityRole="button"
      accessibilityLabel={`Back to ${label}`}
    >
      <Text style={{ color: theme.primary, fontSize: 16, fontWeight: '700' }}>‹ {label}</Text>
    </TouchableOpacity>
  );
}
