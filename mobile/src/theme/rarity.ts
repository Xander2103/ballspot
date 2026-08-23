import type { ThemeTokens } from './themes';

/** Badge rarity accent, shared by Trophy Room and friend profiles. */
export function rarityColor(theme: ThemeTokens, rarity: string): string {
  const map: Record<string, string> = {
    common: theme.textSecondary,
    rare: theme.accent,
    epic: '#b76bff', // no purple token exists; reads fine on every theme surface
    legendary: theme.gold,
  };
  return map[rarity] ?? theme.textSecondary;
}
