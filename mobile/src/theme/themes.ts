// Central, extensible theme system for BallPicker.
//
// Each theme provides the SAME token set so screens can read `theme.<token>`
// without knowing which theme is active. To add a theme: add its slug to
// ThemeName, add a palette below, add a THEME_META entry, and (backend) add the
// slug to config('ballspot.themes'). "tournament_blue" is ORIGINAL styling
// inspired by the energy of televised sport nights — it is NOT UEFA branding.

export type ThemeName =
  | 'classic'
  | 'tournament_blue'
  | 'pitch_green'
  | 'sunset_orange'
  | 'high_contrast';

export interface ThemeTokens {
  // Surfaces
  background: string;
  surface: string;
  surfaceElevated: string;
  // Brand
  primary: string;
  primaryDark: string;
  secondary: string;
  accent: string;
  // Status
  warning: string;
  success: string;
  danger: string;
  error: string; // alias of danger, kept for existing call sites
  // Text
  text: string;
  textSecondary: string;
  textMuted: string;
  // Lines / misc
  border: string;
  overlay: string;
  // Semantic extras
  score: string;
  gold: string;
  silver: string;
  bronze: string;
  // Text color to place ON the primary button (contrast-safe per theme)
  onPrimary: string;
}

// Current BallSpot look — football green on deep navy.
const classic: ThemeTokens = {
  background: '#0a1628',
  surface: '#132038',
  surfaceElevated: '#1a2d4a',
  primary: '#00c853',
  primaryDark: '#009624',
  secondary: '#2979ff',
  accent: '#2979ff',
  warning: '#ffab40',
  success: '#00c853',
  danger: '#ff5252',
  error: '#ff5252',
  text: '#ffffff',
  textSecondary: '#8ba0b8',
  textMuted: '#4a6080',
  border: '#1e3050',
  overlay: 'rgba(0,0,0,0.6)',
  score: '#00c853',
  gold: '#ffd54f',
  silver: '#cfd8dc',
  bronze: '#cd8e5a',
  onPrimary: '#00160a',
};

// European tournament night — deep navy, turquoise energy, vivid red accent,
// cool silver. Broadcast-premium, sporty. Original palette (not UEFA).
const tournament_blue: ThemeTokens = {
  background: '#0a1024',
  surface: '#121a3a',
  surfaceElevated: '#1b2652',
  primary: '#19d3c5',
  primaryDark: '#0fa89d',
  secondary: '#c2cbdd',
  accent: '#ff3b52',
  warning: '#ffb020',
  success: '#2ce59b',
  danger: '#ff3b52',
  error: '#ff3b52',
  text: '#f4f8ff',
  textSecondary: '#9fb0d6',
  textMuted: '#5f6f9c',
  border: '#25335f',
  overlay: 'rgba(4,8,22,0.72)',
  score: '#19d3c5',
  gold: '#ffcf5c',
  silver: '#cfd6ea',
  bronze: '#d08a53',
  onPrimary: '#04201d',
};

// Stadium pitch — grass green focus with gold line-marking accent.
const pitch_green: ThemeTokens = {
  background: '#06170f',
  surface: '#0d2318',
  surfaceElevated: '#153122',
  primary: '#33d17a',
  primaryDark: '#1e9e57',
  secondary: '#a5d6a7',
  accent: '#ffd740',
  warning: '#ffb300',
  success: '#33d17a',
  danger: '#ef5350',
  error: '#ef5350',
  text: '#f2fff6',
  textSecondary: '#9dc4ab',
  textMuted: '#5e8069',
  border: '#1c3d2a',
  overlay: 'rgba(0,10,5,0.62)',
  score: '#33d17a',
  gold: '#ffd740',
  silver: '#d7e3d9',
  bronze: '#c88a52',
  onPrimary: '#03160c',
};

// Warm, energetic sunset — orange primary, yellow secondary.
const sunset_orange: ThemeTokens = {
  background: '#1c0f06',
  surface: '#2a170a',
  surfaceElevated: '#3a2010',
  primary: '#ff8c1a',
  primaryDark: '#e06d00',
  secondary: '#ffd166',
  accent: '#ff5470',
  warning: '#ffca3a',
  success: '#52c41a',
  danger: '#ff5470',
  error: '#ff5470',
  text: '#fff6ec',
  textSecondary: '#d8a878',
  textMuted: '#9a6f47',
  border: '#472712',
  overlay: 'rgba(20,8,0,0.62)',
  score: '#ff8c1a',
  gold: '#ffd166',
  silver: '#e6d9c8',
  bronze: '#d18b4e',
  onPrimary: '#241000',
};

// Accessibility-focused high contrast — pure black, high-vis yellow, strong text.
const high_contrast: ThemeTokens = {
  background: '#000000',
  surface: '#0d0d0d',
  surfaceElevated: '#1a1a1a',
  primary: '#ffd400',
  primaryDark: '#d9b400',
  secondary: '#00e5ff',
  accent: '#00e5ff',
  warning: '#ffab00',
  success: '#00e676',
  danger: '#ff1744',
  error: '#ff1744',
  text: '#ffffff',
  textSecondary: '#e6e6e6',
  textMuted: '#b3b3b3',
  border: '#7a7a7a',
  overlay: 'rgba(0,0,0,0.8)',
  score: '#ffd400',
  gold: '#ffd400',
  silver: '#e0e0e0',
  bronze: '#d0904e',
  onPrimary: '#000000',
};

export const themes: Record<ThemeName, ThemeTokens> = {
  classic,
  tournament_blue,
  pitch_green,
  sunset_orange,
  high_contrast,
};

// Pitch Green is the default look for anyone without a saved preference;
// users who picked a theme keep it (local storage / server value wins).
export const DEFAULT_THEME: ThemeName = 'pitch_green';

export function isThemeName(value: unknown): value is ThemeName {
  return typeof value === 'string' && value in themes;
}

// Metadata for the theme picker UI (label, one-liner, preview swatches).
export interface ThemeMeta {
  name: ThemeName;
  label: string;
  description: string;
  swatches: string[];
}

export const THEME_META: ThemeMeta[] = [
  { name: 'classic', label: 'Classic', description: 'The original BallPicker look.', swatches: [classic.background, classic.primary, classic.accent] },
  { name: 'tournament_blue', label: 'Tournament Blue', description: 'European night — navy, turquoise & red.', swatches: [tournament_blue.background, tournament_blue.primary, tournament_blue.accent] },
  { name: 'pitch_green', label: 'Pitch Green', description: 'Stadium grass focus.', swatches: [pitch_green.background, pitch_green.primary, pitch_green.accent] },
  { name: 'sunset_orange', label: 'Sunset Orange', description: 'Warm & energetic.', swatches: [sunset_orange.background, sunset_orange.primary, sunset_orange.accent] },
  { name: 'high_contrast', label: 'High Contrast', description: 'Maximum readability.', swatches: [high_contrast.background, high_contrast.primary, high_contrast.accent] },
];
