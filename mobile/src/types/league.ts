import { User } from './auth';

export interface League {
  id: number;
  name: string;
  join_code: string;
  duration_days: number;
  rounds_per_day: number;
  status: 'active' | 'finished';
  total_rounds: number;
  members_count: number;
  members?: User[];
}
