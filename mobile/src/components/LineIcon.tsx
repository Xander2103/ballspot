import React from 'react';
import Svg, { Circle, Path, Rect } from 'react-native-svg';

export type LineIconName =
  | 'home'
  | 'copy'
  | 'check'
  | 'qr'
  | 'scan'
  | 'clock'
  | 'chevronDown'
  | 'plus'
  | 'close'
  | 'search';

interface Props {
  name: LineIconName;
  color: string;
  size?: number;
  strokeWidth?: number;
}

/**
 * Small utility icons (header buttons, friend code actions, the Daily
 * countdown), drawn like TabIcon: a 24-unit grid with round strokes, so they
 * sit next to the tab bar and sport icons as one set.
 */
export function LineIcon({ name, color, size = 20, strokeWidth = 1.9 }: Props) {
  const common = {
    stroke: color,
    strokeWidth,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    fill: 'none' as const,
  };

  switch (name) {
    case 'home':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M4 10.6 12 4l8 6.6" {...common} />
          <Path d="M6.2 9v10.2a.8.8 0 0 0 .8.8h3.6v-5.4h2.8V20H17a.8.8 0 0 0 .8-.8V9" {...common} />
        </Svg>
      );
    case 'copy':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Rect x={8.5} y={8.5} width={11.5} height={11.5} rx={2.4} {...common} />
          <Path d="M15.5 8.5V6.4A2.4 2.4 0 0 0 13.1 4H6.4A2.4 2.4 0 0 0 4 6.4v6.7a2.4 2.4 0 0 0 2.4 2.4h2.1" {...common} />
        </Svg>
      );
    case 'check':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="m5 12.5 4.4 4.3L19 7.2" {...common} />
        </Svg>
      );
    case 'qr':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Rect x={4} y={4} width={6} height={6} rx={1.2} {...common} />
          <Rect x={14} y={4} width={6} height={6} rx={1.2} {...common} />
          <Rect x={4} y={14} width={6} height={6} rx={1.2} {...common} />
          <Path d="M14 14h2.5v2.5H14ZM18.5 14H20M14 19.5V20M17.5 18.5H20V20h-2.5" {...common} />
        </Svg>
      );
    case 'scan':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M4 8.5V6a2 2 0 0 1 2-2h2.5M15.5 4H18a2 2 0 0 1 2 2v2.5M20 15.5V18a2 2 0 0 1-2 2h-2.5M8.5 20H6a2 2 0 0 1-2-2v-2.5" {...common} />
          <Rect x={8.2} y={8.2} width={3} height={3} rx={0.6} {...common} />
          <Rect x={12.8} y={12.8} width={3} height={3} rx={0.6} {...common} />
          <Path d="M12.8 8.2h3v3M8.2 12.8v3h3" {...common} />
        </Svg>
      );
    case 'clock':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={8.5} {...common} />
          <Path d="M12 7.5V12l3 2" {...common} />
        </Svg>
      );
    case 'chevronDown':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="m6.5 9.5 5.5 5.5 5.5-5.5" {...common} />
        </Svg>
      );
    case 'plus':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M12 5v14M5 12h14" {...common} />
        </Svg>
      );
    case 'close':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M6.5 6.5l11 11M17.5 6.5l-11 11" {...common} />
        </Svg>
      );
    case 'search':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={10.8} cy={10.8} r={6.3} {...common} />
          <Path d="m15.5 15.5 4.5 4.5" {...common} />
        </Svg>
      );
  }
}
