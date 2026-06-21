export interface Challenge {
  id: number;
  title: string;
  difficulty: 'easy' | 'medium' | 'hard';
  hidden_image_url: string;
}

export interface LeagueRound {
  id: number;
  round_number: number;
  status: 'open' | 'closed';
  challenge: Challenge;
}

export interface CurrentRoundResponse {
  current_round: LeagueRound | null;
  completed: boolean;
}
