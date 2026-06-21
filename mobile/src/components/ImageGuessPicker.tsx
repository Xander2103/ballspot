import React, { useState } from 'react';
import { View, Image, StyleSheet, TouchableOpacity, Text, Dimensions } from 'react-native';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';

const SCREEN_W = Dimensions.get('window').width;
const IMAGE_H = Math.round(SCREEN_W * (9 / 16));

interface Marker {
  x_ratio: number;
  y_ratio: number;
  color: string;
  label: string;
}

interface Props {
  imageUri: string;
  onGuess?: (xRatio: number, yRatio: number) => void;
  markers?: Marker[];
  interactive?: boolean;
}

export function ImageGuessPicker({ imageUri, onGuess, markers = [], interactive = true }: Props) {
  const [guess, setGuess] = useState<{ x: number; y: number } | null>(null);
  const width = SCREEN_W;
  const height = IMAGE_H;

  function handlePress(e: { nativeEvent: { locationX: number; locationY: number } }) {
    if (!interactive || !onGuess) return;
    const { locationX, locationY } = e.nativeEvent;
    const xRatio = Math.min(1, Math.max(0, locationX / width));
    const yRatio = Math.min(1, Math.max(0, locationY / height));
    setGuess({ x: locationX, y: locationY });
    onGuess(xRatio, yRatio);
  }

  return (
    <View style={styles.container}>
      <TouchableOpacity
        activeOpacity={1}
        onPress={handlePress}
        disabled={!interactive}
        style={{ width, height }}
      >
        <Image
          source={{ uri: imageUri }}
          style={{ width, height }}
          resizeMode="cover"
        />
        {guess && interactive && (
          <View style={[styles.marker, styles.guessMarker, { left: guess.x - 12, top: guess.y - 12 }]} />
        )}
        {markers.map((m, i) => (
          <View
            key={i}
            style={[styles.marker, { backgroundColor: m.color, left: m.x_ratio * width - 12, top: m.y_ratio * height - 12 }]}
          >
            <Text style={styles.markerLabel}>{m.label}</Text>
          </View>
        ))}
      </TouchableOpacity>
      {interactive && (
        <Text style={styles.hint}>
          {guess ? '✓ Tap to reposition' : 'Tap on the image to guess the ball position'}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { width: SCREEN_W },
  marker: {
    position: 'absolute',
    width: 24,
    height: 24,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  guessMarker: { backgroundColor: colors.accent },
  markerLabel: { fontSize: 8, color: '#fff', fontWeight: '700' },
  hint: {
    textAlign: 'center',
    color: colors.textSecondary,
    fontSize: 13,
    padding: spacing.sm,
    backgroundColor: colors.surface,
  },
});
