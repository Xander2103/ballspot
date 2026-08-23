import type { ViewStyle } from 'react-native';
import type { ThemeTokens } from './themes';

/**
 * Rank-tier visual treatment for rank cards: the higher the rank, the more
 * impressive the border/glow. Levels: 1 Rookie, 2 Amateur, 3 Pro, 4 Elite,
 * 5 Legend, 6 Ball Master (server config ballspot.ranks).
 *
 * Static glow only — no animation dependency. `boxShadow` is the RN 0.76+
 * cross-platform style (Android/iOS/web); hex suffixes are alpha.
 * Missing/unknown level falls back to the plain themed border.
 */
export function getRankVisualStyle(
  level: number | null | undefined,
  theme: ThemeTokens,
): ViewStyle {
  switch (level) {
    case 1: // Rookie — muted bronze border
      return { borderWidth: 1, borderColor: theme.bronze };
    case 2: // Amateur — silver border
      return { borderWidth: 1, borderColor: theme.silver };
    case 3: // Pro — subtle primary glow
      return {
        borderWidth: 2,
        borderColor: theme.primary,
        boxShadow: `0 0 8px ${theme.primary}59`,
      };
    case 4: // Elite — gold border, medium glow
      return {
        borderWidth: 2,
        borderColor: theme.gold,
        boxShadow: `0 0 10px ${theme.gold}73`,
      };
    case 5: // Legend — strong gold glow
      return {
        borderWidth: 2,
        borderColor: theme.gold,
        boxShadow: `0 0 14px ${theme.gold}A6`,
      };
    case 6: // Ball Master — legendary layered gold glow
      return {
        borderWidth: 3,
        borderColor: theme.gold,
        boxShadow: `0 0 20px ${theme.gold}CC, 0 0 6px ${theme.gold}80`,
      };
    default:
      return { borderWidth: 1, borderColor: theme.border };
  }
}
