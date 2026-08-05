import React from 'react';
import Svg, { Circle, Path } from 'react-native-svg';

export type TabIconName = 'play' | 'tournaments' | 'friends' | 'profile';

interface Props {
  name: TabIconName;
  color: string;
  focused: boolean;
  size?: number;
}

/**
 * Line icons for the bottom tab bar, drawn with react-native-svg so they match
 * the active theme without pulling in an icon-font dependency. The focused
 * state uses a slightly heavier stroke (color is handled by the tab bar).
 */
export function TabIcon({ name, color, focused, size = 24 }: Props) {
  const stroke = focused ? 2.2 : 1.8;
  const common = {
    stroke: color,
    strokeWidth: stroke,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    fill: 'none' as const,
  };

  switch (name) {
    case 'play':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path
            d="M10 8.6 L15.8 12 L10 15.4 Z"
            stroke={color}
            strokeWidth={stroke}
            strokeLinejoin="round"
            fill={focused ? color : 'none'}
          />
        </Svg>
      );
    case 'tournaments':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M8 4h8v5.5a4 4 0 0 1-8 0V4Z" {...common} />
          <Path d="M8 5.5H5v.8A3.2 3.2 0 0 0 8.2 9.5" {...common} />
          <Path d="M16 5.5h3v.8a3.2 3.2 0 0 1-3.2 3.2" {...common} />
          <Path d="M12 13.5V17" {...common} />
          <Path d="M8.5 20h7" {...common} />
          <Path d="M12 17c-1.8 0-2.8 1.2-3 3M12 17c1.8 0 2.8 1.2 3 3" {...common} />
        </Svg>
      );
    case 'friends':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={9} cy={8.3} r={3.1} {...common} />
          <Path d="M3.5 19.3c.4-3 2.6-4.7 5.5-4.7s5.1 1.7 5.5 4.7" {...common} />
          <Path d="M15.4 5.6a3.1 3.1 0 0 1 0 5.4" {...common} />
          <Path d="M16.6 14.9c2.3.5 3.7 2 4 4.4" {...common} />
        </Svg>
      );
    case 'profile':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={8} r={3.4} {...common} />
          <Path d="M5.5 19.5c.5-3.5 3-5.5 6.5-5.5s6 2 6.5 5.5" {...common} />
        </Svg>
      );
  }
}
