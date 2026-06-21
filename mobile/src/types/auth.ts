export interface User {
  id: number;
  name: string;
  username: string;
  email?: string;
}

export interface AuthState {
  user: User | null;
  token: string | null;
}
