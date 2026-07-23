import type { SportSummary } from './daily';

export interface ChallengePackSummary {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sport: SportSummary | null;
  cover_image_url: string | null;
  difficulty: string | null;
  challenge_count: number;
  is_featured: boolean;
}

/** Safe, non-sensitive challenge summary (never includes the ball position). */
export interface PackChallengeSummary {
  id: number;
  title: string;
  difficulty: string;
  hidden_image_url: string | null;
  sport: { slug: string; name: string; emoji: string } | null;
  category: { name: string; slug: string } | null;
}

export interface ChallengePackDetail extends ChallengePackSummary {
  challenges: PackChallengeSummary[];
}
