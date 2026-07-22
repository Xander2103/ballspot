import React, { useEffect, useState } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { View, ActivityIndicator } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { isThemeName } from '../theme/themes';
import { tokenStorage } from '../storage/tokenStorage';
import { authApi } from '../api/authApi';

import type { Badge } from '../types/badge';
import type { RankProgress, RankUp } from '../types/auth';
import { LoginScreen } from '../screens/LoginScreen';
import { LoginVerificationScreen } from '../screens/LoginVerificationScreen';
import { EmailVerificationScreen } from '../screens/EmailVerificationScreen';
import { RegisterScreen } from '../screens/RegisterScreen';
import { ForgotPasswordScreen } from '../screens/ForgotPasswordScreen';
import { ResetPasswordScreen } from '../screens/ResetPasswordScreen';
import { HomeScreen } from '../screens/HomeScreen';
import { SportSelectionScreen } from '../screens/SportSelectionScreen';
import { CreateLeagueScreen } from '../screens/CreateLeagueScreen';
import { JoinLeagueScreen } from '../screens/JoinLeagueScreen';
import { LeagueDetailScreen } from '../screens/LeagueDetailScreen';
import { GuessScreen } from '../screens/GuessScreen';
import { ResultScreen } from '../screens/ResultScreen';
import { LeaderboardScreen } from '../screens/LeaderboardScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import { DailyChallengeScreen } from '../screens/DailyChallengeScreen';
import { DailyResultScreen } from '../screens/DailyResultScreen';
import { WeeklyLeaderboardScreen } from '../screens/WeeklyLeaderboardScreen';

export type RootStackParamList = {
  Login: undefined;
  LoginVerification: { verificationId: string; email?: string };
  EmailVerification: { email?: string } | undefined;
  Register: undefined;
  ForgotPassword: undefined;
  ResetPassword: { email?: string; token?: string } | undefined;
  Home: undefined;
  SportSelection: { mode?: 'onboarding' | 'change'; currentSportId?: number | null } | undefined;
  Profile: undefined;
  CreateLeague: undefined;
  JoinLeague: undefined;
  LeagueDetail: { leagueId: number; leagueName: string };
  Guess: { leagueId: number; roundId: number; leagueName: string };
  Result: { roundId: number; leagueId: number; imageUrl: string; leagueName: string; categoryName?: string | null; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null };
  Leaderboard: { leagueId: number; leagueName: string };
  DailyChallenge: { dailyChallengeId: number };
  DailyResult: { dailyChallengeId: number; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null };
  WeeklyLeaderboard: undefined;
};

const Stack = createNativeStackNavigator<RootStackParamList>();

export function AppNavigator() {
  const { theme, setTheme } = useTheme();
  const [initialRoute, setInitialRoute] = useState<keyof RootStackParamList | null>(null);

  useEffect(() => {
    (async () => {
      const token = await tokenStorage.get().catch(() => null);
      if (!token) {
        setInitialRoute('Login');
        return;
      }
      try {
        const user = await authApi.me();
        // Apply the server-side theme without re-syncing it back.
        if (user.selected_theme && isThemeName(user.selected_theme)) {
          setTheme(user.selected_theme, { sync: false });
        }
        // Unverified email → verify first; no preferred sport → onboarding;
        // otherwise straight to Home.
        if (user.email_verified === false) {
          setInitialRoute('EmailVerification');
        } else {
          setInitialRoute(user.preferred_sport ? 'Home' : 'SportSelection');
        }
      } catch (e: any) {
        // 401 → stale token, send to Login. Any other error (offline) → let
        // the user in rather than locking them out.
        setInitialRoute(e?.status === 401 ? 'Login' : 'Home');
      }
    })();
  }, []);

  const screenOptions = {
    headerStyle: { backgroundColor: theme.surface },
    headerTintColor: theme.text,
    headerTitleStyle: { fontWeight: '700' as const, color: theme.text },
    contentStyle: { backgroundColor: theme.background },
  };

  if (initialRoute === null) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      <Stack.Navigator initialRouteName={initialRoute} screenOptions={screenOptions}>
        <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        <Stack.Screen name="LoginVerification" component={LoginVerificationScreen} options={{ title: 'Verify Login' }} />
        <Stack.Screen name="EmailVerification" component={EmailVerificationScreen} options={{ title: 'Verify Email', headerLeft: () => null }} />
        <Stack.Screen name="Register" component={RegisterScreen} options={{ title: 'Create Account' }} />
        <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: 'Forgot Password' }} />
        <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: 'Reset Password' }} />
        <Stack.Screen name="Home" component={HomeScreen} options={{ title: 'BallSpot', headerLeft: () => null }} />
        <Stack.Screen name="SportSelection" component={SportSelectionScreen} options={{ title: 'Choose Sport' }} />
        <Stack.Screen name="Profile" component={ProfileScreen} options={{ title: 'Profile' }} />
        <Stack.Screen name="CreateLeague" component={CreateLeagueScreen} options={{ title: 'Create Tournament' }} />
        <Stack.Screen name="JoinLeague" component={JoinLeagueScreen} options={{ title: 'Join Tournament' }} />
        <Stack.Screen name="LeagueDetail" component={LeagueDetailScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen name="Guess" component={GuessScreen} options={{ title: 'Make Your Guess', gestureEnabled: false }} />
        <Stack.Screen name="Result" component={ResultScreen} options={{ title: 'Round Result', gestureEnabled: false }} />
        <Stack.Screen name="Leaderboard" component={LeaderboardScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen name="DailyChallenge" component={DailyChallengeScreen} options={{ title: 'Daily Ball Challenge', gestureEnabled: false }} />
        <Stack.Screen name="DailyResult" component={DailyResultScreen} options={{ title: 'Daily Result', gestureEnabled: false }} />
        <Stack.Screen name="WeeklyLeaderboard" component={WeeklyLeaderboardScreen} options={{ title: 'Weekly Leaderboard' }} />
      </Stack.Navigator>
    </NavigationContainer>
  );
}
