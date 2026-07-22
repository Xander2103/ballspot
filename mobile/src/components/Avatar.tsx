import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { Image } from 'expo-image';
import { useTheme } from '../theme/useTheme';

interface Props {
  uri?: string | null;
  name?: string | null;
  size?: number;
  /** Optional ring color (defaults to theme primary). */
  ring?: string;
}

function initials(name?: string | null): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p.charAt(0).toUpperCase()).join('') || '?';
}

/** Circular avatar: shows the uploaded photo, or initials on a branded circle. */
export function Avatar({ uri, name, size = 96, ring }: Props) {
  const { theme } = useTheme();
  const radius = size / 2;
  const border = ring ?? theme.primary;

  if (uri) {
    return (
      <Image
        source={{ uri }}
        style={{ width: size, height: size, borderRadius: radius, borderWidth: 2, borderColor: border }}
        contentFit="cover"
        transition={150}
      />
    );
  }

  return (
    <View
      style={[
        styles.fallback,
        {
          width: size,
          height: size,
          borderRadius: radius,
          backgroundColor: theme.surfaceElevated,
          borderColor: border,
        },
      ]}
    >
      <Text style={{ color: theme.primary, fontSize: size * 0.4, fontWeight: '800' }}>
        {initials(name)}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  fallback: {
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
  },
});
