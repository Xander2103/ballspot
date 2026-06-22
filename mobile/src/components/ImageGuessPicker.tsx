import React, { useRef, useState, useCallback } from 'react';
import { View, Image, StyleSheet, Pressable, Text, LayoutChangeEvent } from 'react-native';
import { colors } from '../theme/colors';

const MARKER_SIZE = 40;
const HALF = MARKER_SIZE / 2;

export interface Marker {
  x_ratio: number;
  y_ratio: number;
  color: string;
  label: string;
  emoji?: string;
}

interface Props {
  imageUri: string;
  onGuess?: (xRatio: number, yRatio: number) => void;
  markers?: Marker[];
  interactive?: boolean;
}

export function ImageGuessPicker({ imageUri, onGuess, markers = [], interactive = true }: Props) {
  const containerRef = useRef<View>(null);
  // Actual rendered dimensions captured via onLayout
  const [dims, setDims] = useState({ width: 0, height: 0 });
  const [guess, setGuess] = useState<{ xRatio: number; yRatio: number } | null>(null);

  const handleLayout = useCallback((e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    setDims({ width, height });
  }, []);

  function applyGuess(tapX: number, tapY: number) {
    if (!onGuess || dims.width === 0 || dims.height === 0) return;
    // Clamp to [0, 1] and reject non-finite values
    const xRatio = Math.min(1, Math.max(0, tapX / dims.width));
    const yRatio = Math.min(1, Math.max(0, tapY / dims.height));
    if (!Number.isFinite(xRatio) || !Number.isFinite(yRatio)) return;
    setGuess({ xRatio, yRatio });
    onGuess(xRatio, yRatio);
  }

  function handlePress(e: any) {
    if (!interactive || !onGuess || dims.width === 0) return;

    const { locationX, locationY, pageX, pageY } = e.nativeEvent;

    // locationX/Y are relative to the Pressable on native.
    // On React Native Web, the browser maps offsetX/offsetY to locationX/Y from
    // the event TARGET element. Since the Pressable is the only pointer-active
    // element (Image and markers are pointer-events:none), offsetX/Y should be
    // relative to the Pressable — same as what we want.
    //
    // Guard: check the value is finite and inside the container bounds (±2px
    // tolerance for sub-pixel rounding). Fall back to pageX - container origin
    // via measureInWindow when they are out-of-bounds or undefined.
    if (
      Number.isFinite(locationX) && Number.isFinite(locationY) &&
      locationX >= -2 && locationX <= dims.width + 2 &&
      locationY >= -2 && locationY <= dims.height + 2
    ) {
      applyGuess(locationX, locationY);
    } else if (Number.isFinite(pageX) && Number.isFinite(pageY)) {
      // Fallback: measure container origin in window coordinates then subtract
      containerRef.current?.measureInWindow((cx, cy) => {
        applyGuess(pageX - cx, pageY - cy);
      });
    }
    // If neither path yields valid coords, we silently ignore the tap
  }

  return (
    <View ref={containerRef} style={styles.container} onLayout={handleLayout}>
      {/* Image layer — pointer-events:none so Pressable above receives all taps */}
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        <Image
          source={{ uri: imageUri }}
          style={StyleSheet.absoluteFill}
          resizeMode="cover"
        />
      </View>

      {/* Transparent tap-capture layer (interactive mode only) */}
      {interactive && (
        <Pressable style={StyleSheet.absoluteFill} onPress={handlePress} />
      )}

      {/* User's guess marker — rendered above Pressable, pointer-events:none */}
      {guess && interactive && dims.width > 0 && (
        <View
          pointerEvents="none"
          style={[
            styles.markerBase,
            styles.guessMarker,
            {
              left: guess.xRatio * dims.width - HALF,
              top: guess.yRatio * dims.height - HALF,
            },
          ]}
        >
          <Text style={styles.markerEmoji}>⚽</Text>
        </View>
      )}

      {/* Result / reveal markers — pointer-events:none */}
      {dims.width > 0 && markers.map((m, i) => {
        const left = m.x_ratio * dims.width - HALF;
        const top = m.y_ratio * dims.height - HALF;
        // Skip markers whose ratios resolved to NaN (malformed data guard)
        if (!Number.isFinite(left) || !Number.isFinite(top)) return null;
        return (
          <View
            key={i}
            pointerEvents="none"
            style={[
              styles.markerBase,
              { backgroundColor: m.color, left, top },
            ]}
          >
            <Text style={styles.markerEmoji}>{m.emoji ?? m.label}</Text>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    aspectRatio: 4 / 3,
    backgroundColor: colors.surface,
    borderRadius: 12,
    overflow: 'hidden',
  },
  markerBase: {
    position: 'absolute',
    width: MARKER_SIZE,
    height: MARKER_SIZE,
    borderRadius: HALF,
    borderWidth: 3,
    borderColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center',
    // Shadow gives visibility on both light and dark image regions
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.75,
    shadowRadius: 4,
    elevation: 8,
  },
  guessMarker: {
    backgroundColor: 'rgba(41, 121, 255, 0.85)',
  },
  markerEmoji: {
    fontSize: 18,
  },
});
