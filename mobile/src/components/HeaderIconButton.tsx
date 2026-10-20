import React from 'react';
import { TouchableOpacity, StyleSheet } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { LineIcon, LineIconName } from './LineIcon';

interface Props {
  icon: LineIconName;
  onPress: () => void;
  accessibilityLabel: string;
  testID?: string;
}

/**
 * Quiet icon-only header button (tab headers): a 44pt touch target around a
 * 22pt line icon in the secondary text colour — present, never loud.
 */
export function HeaderIconButton({ icon, onPress, accessibilityLabel, testID }: Props) {
  const { theme } = useTheme();
  return (
    <TouchableOpacity
      onPress={onPress}
      style={styles.button}
      activeOpacity={0.6}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      testID={testID}
    >
      <LineIcon name={icon} color={theme.textSecondary} size={22} />
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  button: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
});
