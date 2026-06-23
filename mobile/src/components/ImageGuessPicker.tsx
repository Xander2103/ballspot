import React, { useRef, useState, useCallback } from 'react';
import { View, Image, StyleSheet, Pressable, Text, LayoutChangeEvent } from 'react-native';
import { colors } from '../theme/colors';

export type MarkerType = 'ghost-ball' | 'glow' | 'default';

export interface Marker {
  x_ratio: number;
  y_ratio: number;
  type: MarkerType;
}

interface Props {
  imageUri: string;
  onGuess?: (xRatio: number, yRatio: number) => void;
  markers?: Marker[];
  interactive?: boolean;
}

const GHOST_SIZE = 42;
const GLOW_SIZE = 60;
const DEFAULT_SIZE = 40;

export function ImageGuessPicker({ imageUri, onGuess, markers = [], interactive = true }: Props) {
  const containerRef = useRef<View>(null);
  const [dims, setDims] = useState({ width: 0, height: 0 });
  const [guess, setGuess] = useState<{ xRatio: number; yRatio: number } | null>(null);

  const handleLayout = useCallback((e: LayoutChangeEvent) => {
    const { width, height } = e.nativeEvent.layout;
    setDims({ width, height });
  }, []);

  function applyGuess(tapX: number, tapY: number) {
    if (!onGuess || dims.width === 0 || dims.height === 0) return;
    const xRatio = Math.min(1, Math.max(0, tapX / dims.width));
    const yRatio = Math.min(1, Math.max(0, tapY / dims.height));
    if (!Number.isFinite(xRatio) || !Number.isFinite(yRatio)) return;
    setGuess({ xRatio, yRatio });
    onGuess(xRatio, yRatio);
  }

  function handlePress(e: any) {
    if (!interactive || !onGuess || dims.width === 0) return;
    const { locationX, locationY, pageX, pageY } = e.nativeEvent;
    if (
      Number.isFinite(locationX) && Number.isFinite(locationY) &&
      locationX >= -2 && locationX <= dims.width + 2 &&
      locationY >= -2 && locationY <= dims.height + 2
    ) {
      applyGuess(locationX, locationY);
    } else if (Number.isFinite(pageX) && Number.isFinite(pageY)) {
      containerRef.current?.measureInWindow((cx, cy) => {
        applyGuess(pageX - cx, pageY - cy);
      });
    }
  }

  function renderMarker(m: Marker, key: number | string) {
    const size = m.type === 'glow' ? GLOW_SIZE : m.type === 'ghost-ball' ? GHOST_SIZE : DEFAULT_SIZE;
    const half = size / 2;
    const left = m.x_ratio * dims.width - half;
    const top = m.y_ratio * dims.height - half;
    if (!Number.isFinite(left) || !Number.isFinite(top)) return null;

    const style = m.type === 'ghost-ball'
      ? styles.markerGhostBall
      : m.type === 'glow'
      ? styles.markerGlow
      : styles.markerDefault;

    return (
      <View
        key={key}
        pointerEvents="none"
        style={[
          styles.markerBase,
          style,
          { width: size, height: size, borderRadius: half, left, top },
        ]}
      >
        {m.type === 'ghost-ball' && <Text style={styles.ghostEmoji}>⚽</Text>}
      </View>
    );
  }

  return (
    <View ref={containerRef} style={styles.container} onLayout={handleLayout}>
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        <Image source={{ uri: imageUri }} style={StyleSheet.absoluteFill} resizeMode="cover" />
      </View>

      {interactive && (
        <Pressable style={StyleSheet.absoluteFill} onPress={handlePress} />
      )}

      {/* Ghost-ball guess marker in interactive mode */}
      {guess && interactive && dims.width > 0 && renderMarker(
        { x_ratio: guess.xRatio, y_ratio: guess.yRatio, type: 'ghost-ball' },
        'guess'
      )}

      {/* Result markers */}
      {dims.width > 0 && markers.map((m, i) => renderMarker(m, i))}
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
    alignItems: 'center',
    justifyContent: 'center',
  },
  markerGhostBall: {
    opacity: 0.72,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 2,
    borderColor: 'rgba(255,255,255,0.85)',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.4,
    shadowRadius: 3,
    elevation: 4,
  },
  markerGlow: {
    backgroundColor: 'transparent',
    borderWidth: 3,
    borderColor: '#00E676',
    shadowColor: '#00E676',
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.9,
    shadowRadius: 8,
    elevation: 10,
  },
  markerDefault: {
    backgroundColor: 'rgba(0,230,118,0.85)',
    borderWidth: 3,
    borderColor: '#ffffff',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.6,
    shadowRadius: 4,
    elevation: 8,
  },
  ghostEmoji: {
    fontSize: 20,
  },
});
