export interface Badge {
  id: number;
  code: string;
  name: string;
  description: string;
  icon: string;
  category: string;
  rarity: string;
  sort_order: number;
}

export interface EarnedBadge extends Badge {
  earned_at: string | null;
  earned: boolean;
}

export interface BadgeCollection {
  earned_count: number;
  total_count: number;
  badges: EarnedBadge[];
}
