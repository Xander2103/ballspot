import React from 'react';
import { StyleSheet } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';

interface Props {
  /** Unique per screen: gradient ids share one namespace on web. */
  id: string;
  /** The card surface the image fades into. */
  color: string;
  /** Where (0..1 from the top) the fade starts; above it the image is untouched. */
  start?: number;
}

/**
 * Top-to-bottom fade from transparent into the card colour, laid over a card
 * image so the title and actions below it stay readable. react-native-svg is
 * already a dependency, so this needs no native gradient module.
 */
export function CardGradient({ id, color, start = 0.35 }: Props) {
  return (
    <Svg style={StyleSheet.absoluteFill} width="100%" height="100%" preserveAspectRatio="none" pointerEvents="none">
      <Defs>
        <LinearGradient id={id} x1="0" y1="0" x2="0" y2="1">
          <Stop offset={0} stopColor={color} stopOpacity={0} />
          <Stop offset={start} stopColor={color} stopOpacity={0} />
          <Stop offset={Math.min(start + (1 - start) * 0.55, 0.95)} stopColor={color} stopOpacity={0.72} />
          <Stop offset={1} stopColor={color} stopOpacity={1} />
        </LinearGradient>
      </Defs>
      <Rect x="0" y="0" width="100%" height="100%" fill={`url(#${id})`} />
    </Svg>
  );
}
