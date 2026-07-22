export type SportStatus = 'active' | 'coming_soon' | 'hidden';

export interface Sport {
  id: number;
  name: string;
  slug: string;
  emoji: string;
  object_name: string;
  primary_color: string;
  tagline?: string;
  status: SportStatus;
  is_playable: boolean;
  is_coming_soon: boolean;
  // Retained for backward compatibility (mirrors status === 'active').
  is_active: boolean;
}
