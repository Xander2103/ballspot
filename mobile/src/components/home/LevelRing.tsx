import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import Svg, { Circle } from 'react-native-svg';
import { useTheme } from '../../theme/useTheme';
import { useI18n } from '../../i18n';
import type { LevelRingModel } from '../../utils/homeModel';

interface Props {
  model: LevelRingModel;
  onPress: () => void;
  size?: number;
}

/**
 * The player's level in a thin ring; the arc is the progress to the next
 * rank. Tapping it opens Profile, where the full rank card lives (this is the
 * shortcut the header avatar used to be).
 */
export function LevelRing({ model, onPress, size = 46 }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const stroke = 3;
  const r = (size - stroke) / 2;
  const circumference = 2 * Math.PI * r;

  return (
    <TouchableOpacity
      onPress={onPress}
      activeOpacity={0.8}
      hitSlop={8}
      accessibilityRole="button"
      accessibilityLabel={t('home.level.a11y', { level: model.level })}
      style={{ width: size, height: size }}
      testID="home-level-ring"
    >
      <Svg width={size} height={size}>
        <Circle cx={size / 2} cy={size / 2} r={r} stroke={theme.border} strokeWidth={stroke} fill="none" />
        {model.progress > 0 ? (
          <Circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            stroke={theme.primary}
            strokeWidth={stroke}
            strokeLinecap="round"
            fill="none"
            strokeDasharray={`${circumference} ${circumference}`}
            strokeDashoffset={circumference * (1 - model.progress)}
            // Start the arc at 12 o'clock.
            transform={`rotate(-90 ${size / 2} ${size / 2})`}
          />
        ) : null}
      </Svg>
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        <View style={styles.center}>
          <Text style={[styles.level, { color: theme.primary }]} maxFontSizeMultiplier={1.2}>
            {model.level}
          </Text>
        </View>
      </View>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  level: { fontSize: 17, fontWeight: '800' },
});
