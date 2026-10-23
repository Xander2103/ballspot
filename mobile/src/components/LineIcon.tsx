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
  | 'search'
  // Profile 2.0 / Settings
  | 'settings'
  | 'camera'
  | 'chevronRight'
  | 'trophy'
  | 'stats'
  | 'flame'
  | 'medal'
  | 'share'
  | 'star'
  | 'palette'
  | 'globe'
  | 'bell'
  | 'shield'
  | 'logout'
  | 'external'
  | 'trash'
  | 'info'
  | 'help';

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
    case 'settings':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={3} {...common} />
          <Path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" {...common} />
        </Svg>
      );
    case 'camera':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2.2l1.3-2h6l1.3 2h2.2A1.5 1.5 0 0 1 20 8.5V17a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17z" {...common} />
          <Circle cx={12} cy={12.5} r={3.2} {...common} />
        </Svg>
      );
    case 'chevronRight':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="m9.5 6.5 5.5 5.5-5.5 5.5" {...common} />
        </Svg>
      );
    case 'trophy':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M8 4h8v5.5a4 4 0 0 1-8 0V4Z" {...common} />
          <Path d="M8 5.5H5v.8A3.2 3.2 0 0 0 8.2 9.5" {...common} />
          <Path d="M16 5.5h3v.8a3.2 3.2 0 0 1-3.2 3.2" {...common} />
          <Path d="M12 13.5V17M8.5 20h7M12 17c-1.8 0-2.8 1.2-3 3M12 17c1.8 0 2.8 1.2 3 3" {...common} />
        </Svg>
      );
    case 'stats':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M5.5 19.5v-6M12 19.5v-13M18.5 19.5v-9M3.5 19.5h17" {...common} />
        </Svg>
      );
    case 'flame':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M12 3.5c.6 2.6 3 4 4.1 6.3 1.3 2.7.7 6.2-2.1 7.9a4.9 4.9 0 0 1-7.2-3.9c-.2-2 .9-3.5 1.9-4.6.1 1.3.7 2.2 1.6 2.6-.4-3 .3-5.9 1.7-8.3z" {...common} />
        </Svg>
      );
    case 'medal':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={14.5} r={5} {...common} />
          <Path d="M8.6 10.6 6 3.5h4l2 5 2-5h4l-2.6 7.1" {...common} />
        </Svg>
      );
    case 'share':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M12 3.5v11M8.5 7 12 3.5 15.5 7" {...common} />
          <Path d="M7 11.5H5.5A1.5 1.5 0 0 0 4 13v6a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 20 19v-6a1.5 1.5 0 0 0-1.5-1.5H17" {...common} />
        </Svg>
      );
    case 'star':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="m12 3.8 2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8z" {...common} />
        </Svg>
      );
    case 'palette':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.2 0 1.8-.7 1.8-1.5 0-.7-.5-1-.5-1.7 0-.8.6-1.3 1.4-1.3h1.6a4.2 4.2 0 0 0 4.2-4.2c0-4.6-3.9-8.3-8.5-8.3z" {...common} />
          <Circle cx={7.8} cy={12} r={1.1} fill={color} stroke="none" />
          <Circle cx={9.6} cy={8.2} r={1.1} fill={color} stroke="none" />
          <Circle cx={13.6} cy={7.2} r={1.1} fill={color} stroke="none" />
        </Svg>
      );
    case 'globe':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={8.5} {...common} />
          <Path d="M3.5 12h17M12 3.5c2.6 2.6 2.6 14.4 0 17M12 3.5c-2.6 2.6-2.6 14.4 0 17" {...common} />
        </Svg>
      );
    case 'bell':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M6.5 16.5v-5a5.5 5.5 0 0 1 11 0v5l1.5 1.5h-14z" {...common} />
          <Path d="M10 20.5a2 2 0 0 0 4 0" {...common} />
        </Svg>
      );
    case 'shield':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M12 3.5 5 6.2v5.3c0 4.4 3 7.6 7 9 4-1.4 7-4.6 7-9V6.2z" {...common} />
          <Path d="m9 12.2 2.1 2.1 4-4.2" {...common} />
        </Svg>
      );
    case 'logout':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M9.5 4.5H6A1.5 1.5 0 0 0 4.5 6v12A1.5 1.5 0 0 0 6 19.5h3.5" {...common} />
          <Path d="m15.5 16 4-4-4-4M19.5 12h-10" {...common} />
        </Svg>
      );
    case 'external':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M14 4.5h5.5V10M19.5 4.5 11 13" {...common} />
          <Path d="M17 13.5v4.5a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 5 18V9a1.5 1.5 0 0 1 1.5-1.5H11" {...common} />
        </Svg>
      );
    case 'trash':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M4.5 7h15M9.5 7V5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2M6.5 7l.8 11.3a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4L17.5 7M10 11v5M14 11v5" {...common} />
        </Svg>
      );
    case 'info':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={8.5} {...common} />
          <Path d="M12 11v5.2M12 7.9v.2" {...common} />
        </Svg>
      );
    case 'help':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={8.5} {...common} />
          <Path d="M9.6 9.6a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1.1.9-1.1 1.7v.4M12 16.8v.2" {...common} />
        </Svg>
      );
  }
}
