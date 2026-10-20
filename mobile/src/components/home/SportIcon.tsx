import React from 'react';
import { Text } from 'react-native';
import Svg, { Circle, Ellipse, Path } from 'react-native-svg';

interface Props {
  /** Sport slug from GET /sports. */
  slug: string;
  /** Admin-configured emoji, shown for any sport without a drawn icon. */
  emoji: string;
  color: string;
  size?: number;
}

/**
 * Small line icon per sport, drawn in the same style as TabIcon so the sport
 * row reads as one calm set instead of a row of coloured emoji. A sport the
 * admin adds later falls back to its emoji, so the row never shows a blank.
 */
export function SportIcon({ slug, emoji, color, size = 24 }: Props) {
  const common = {
    stroke: color,
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    fill: 'none' as const,
  };

  switch (slug) {
    case 'football':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M12 8.2l3.4 2.5-1.3 4h-4.2l-1.3-4Z" {...common} fill={color} />
          <Path d="M12 8.2V3.6M15.4 10.7l4.3-1.4M14.1 14.7l2.6 3.6M9.9 14.7l-2.6 3.6M8.6 10.7 4.3 9.3" {...common} />
        </Svg>
      );
    case 'tennis':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M5.7 5.6c2.9 3.4 2.9 9.4 0 12.8M18.3 5.6c-2.9 3.4-2.9 9.4 0 12.8" {...common} />
        </Svg>
      );
    case 'basketball':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Circle cx={12} cy={12} r={9} {...common} />
          <Path d="M12 3v18M3 12h18M6 5.3c2.7 3.6 2.7 9.8 0 13.4M18 5.3c-2.7 3.6-2.7 9.8 0 13.4" {...common} />
        </Svg>
      );
    case 'hockey':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M15.5 3 9.8 16.6a2.4 2.4 0 0 1-2.2 1.5H4" {...common} />
          <Ellipse cx={17.5} cy={18.6} rx={3} ry={1.6} {...common} />
        </Svg>
      );
    case 'american_football':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M4.6 19.4C3.5 13.2 7 6.4 13 4.6c2.3-.7 4.6-.8 6.4-.2.6 1.8.5 4.1-.2 6.4-1.8 6-8.6 9.5-14.6 8.6Z" {...common} />
          <Path d="M9.5 14.5l5-5M10.8 10.8l2.4 2.4M12.6 9.2l2.2 2.2M9.2 12.6l2.2 2.2" {...common} />
        </Svg>
      );
    case 'golf':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M9 19.5V3.5l8 3.6-8 3.6" {...common} />
          <Ellipse cx={10} cy={19.5} rx={5.5} ry={1.5} {...common} />
        </Svg>
      );
    case 'cricket':
      return (
        <Svg width={size} height={size} viewBox="0 0 24 24">
          <Path d="M13.6 6.3l2.5 2.5-8.3 8.3-2.5-2.5Z" {...common} />
          <Path d="M5.3 14.6 3.5 16.4M7.8 17.1 6 18.9M3.5 16.4 6 18.9" {...common} />
          <Circle cx={18} cy={18} r={2.2} {...common} />
        </Svg>
      );
    default:
      return <Text style={{ fontSize: size - 4, lineHeight: size }} allowFontScaling={false}>{emoji}</Text>;
  }
}
