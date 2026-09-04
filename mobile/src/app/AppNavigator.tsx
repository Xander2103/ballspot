import React, { useEffect, useState } from 'react';
import { NavigationContainer, NavigatorScreenParams, LinkingOptions } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { View, ActivityIndicator } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { isThemeName } from '../theme/themes';
import { tokenStorage } from '../storage/tokenStorage';
import { authApi } from '../api/authApi';

import type { Badge } from '../types/badge';
import type { RankProgress, RankUp } from '../types/auth';
import type { TournamentCompletion } from '../types/guess';
import type { PackGuessResult, PackCompletionSummary } from '../types/pack';
import { LoginScreen } from '../screens/LoginScreen';
import { LoginVerificationScreen } from '../screens/LoginVerificationScreen';
import { EmailVerificationScreen } from '../screens/EmailVerificationScreen';
import { RegisterScreen } from '../screens/RegisterScreen';
import { ForgotPasswordScreen } from '../screens/ForgotPasswordScreen';
import { ResetPasswordScreen } from '../screens/ResetPasswordScreen';
import { MainTabs, MainTabParamList } from './MainTabs';
import { SportSelectionScreen } from '../screens/SportSelectionScreen';
import { CreateLeagueScreen } from '../screens/CreateLeagueScreen';
import { JoinLeagueScreen } from '../screens/JoinLeagueScreen';
import { LeagueDetailScreen } from '../screens/LeagueDetailScreen';
import { GuessScreen } from '../screens/GuessScreen';
import { ResultScreen } from '../screens/ResultScreen';
import { LeaderboardScreen } from '../screens/LeaderboardScreen';
import { PacksScreen } from '../screens/PacksScreen';
import { PackDetailScreen } from '../screens/PackDetailScreen';
import { PackGuessScreen } from '../screens/PackGuessScreen';
import { PackResultScreen } from '../screens/PackResultScreen';
import { PackCompleteScreen } from '../screens/PackCompleteScreen';
import { RankOverviewScreen } from '../screens/RankOverviewScreen';
import { TrophyRoomScreen } from '../screens/TrophyRoomScreen';
import { DailyChallengeScreen } from '../screens/DailyChallengeScreen';
import { DailyResultScreen } from '../screens/DailyResultScreen';
import { WeeklyLeaderboardScreen } from '../screens/WeeklyLeaderboardScreen';
import { FriendProfileScreen } from '../screens/FriendProfileScreen';
import { ScanFriendCodeScreen } from '../screens/ScanFriendCodeScreen';
import { HeaderExitButton } from '../components/HeaderExitButton';
import { goHome, goPacks } from './navigationActions';

export type RootStackParamList = {
  Login: undefined;
  LoginVerification: { verificationId: string; email?: string };
  EmailVerification: { email?: string; codeSent?: boolean } | undefined;
  Register: undefined;
  ForgotPassword: undefined;
  ResetPassword: { email?: string; token?: string } | undefined;
  /** Bottom-tab host (Play / Tournaments / Friends / Profile). Keeps the
      historical 'Home' name so every existing reset/navigate keeps working. */
  Home: NavigatorScreenParams<MainTabParamList> | undefined;
  SportSelection: { mode?: 'onboarding' | 'change'; currentSportId?: number | null } | undefined;
  Packs: undefined;
  PackDetail: { slug: string; name: string };
  PackGuess: { slug: string; packName: string };
  PackResult: { slug: string; packName: string; result: PackGuessResult; imageUrl: string | null };
  PackComplete: { slug: string; packName: string; completion?: PackCompletionSummary | null };
  RankOverview: undefined;
  TrophyRoom: undefined;
  CreateLeague: undefined;
  JoinLeague: undefined;
  LeagueDetail: { leagueId: number; leagueName: string };
  Guess: { leagueId: number; roundId: number; leagueName: string };
  Result: { roundId: number; leagueId: number; imageUrl: string; leagueName: string; categoryName?: string | null; challengeTitle?: string | null; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null; tournamentCompletion?: TournamentCompletion };
  Leaderboard: { leagueId: number; leagueName: string };
  DailyChallenge: { dailyChallengeId: number };
  DailyResult: { dailyChallengeId: number; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null };
  WeeklyLeaderboard: undefined;
  FriendProfile: { userId: number; username: string };
  ScanFriendCode: undefined;
};

const Stack = createNativeStackNavigator<RootStackParamList>();

const WEB_BASE = process.env.EXPO_PUBLIC_WEB_URL ?? 'https://ballpicker.vanmalderstudio.be';

/**
 * Deep links. Only the password-reset link is routable from outside the app:
 *   ballpicker://reset-password?token=…&email=…   (offered on the web reset page)
 *   https://<WEB_BASE>/reset-password?token=…      (the link in the email; only
 *   opens the app on platforms where universal/app links are configured —
 *   otherwise the web fallback page handles it)
 * Everything else stays app-internal on purpose: no route may be opened by a
 * URL that would land a signed-out user on an authenticated screen.
 */
const linking: LinkingOptions<RootStackParamList> = {
  prefixes: ['ballpicker://', WEB_BASE],
  config: {
    initialRouteName: 'Login',
    screens: {
      ResetPassword: {
        path: 'reset-password',
        parse: {
          token: (value: string) => decodeURIComponent(value),
          email: (value: string) => decodeURIComponent(value),
        },
      },
    },
  },
};

type AppNavigatorProps = {
  /** Fired once the initial route has been resolved and rendered. */
  onReady?: () => void;
};

export function AppNavigator({ onReady }: AppNavigatorProps = {}) {
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

  // Runs after the resolved route has committed, so the native splash hides
  // over real UI rather than the spinner.
  useEffect(() => {
    if (initialRoute !== null) onReady?.();
  }, [initialRoute, onReady]);

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
    <NavigationContainer linking={linking}>
      <Stack.Navigator initialRouteName={initialRoute} screenOptions={screenOptions}>
        <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        <Stack.Screen name="LoginVerification" component={LoginVerificationScreen} options={{ title: 'Verify Login' }} />
        <Stack.Screen name="EmailVerification" component={EmailVerificationScreen} options={{ title: 'Verify Email', headerLeft: () => null }} />
        <Stack.Screen name="Register" component={RegisterScreen} options={{ title: 'Create Account' }} />
        <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: 'Forgot Password' }} />
        <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: 'Reset Password' }} />
        <Stack.Screen name="Home" component={MainTabs} options={{ headerShown: false }} />
        <Stack.Screen name="SportSelection" component={SportSelectionScreen} options={{ title: 'Choose Sport' }} />
        <Stack.Screen name="Packs" component={PacksScreen} options={{ title: 'Challenge Packs' }} />
        <Stack.Screen name="PackDetail" component={PackDetailScreen} options={({ route }) => ({ title: route.params.name })} />
        <Stack.Screen
          name="PackGuess"
          component={PackGuessScreen}
          options={({ route, navigation }) => ({
            title: route.params.packName,
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Packs" onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="PackResult"
          component={PackResultScreen}
          options={({ navigation }) => ({
            title: 'Pack Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Packs" onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="PackComplete"
          component={PackCompleteScreen}
          options={({ navigation }) => ({
            title: 'Pack Completed',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Packs" onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen name="RankOverview" component={RankOverviewScreen} options={{ title: 'All Ranks' }} />
        <Stack.Screen name="TrophyRoom" component={TrophyRoomScreen} options={{ title: 'Trophy Room' }} />
        <Stack.Screen name="CreateLeague" component={CreateLeagueScreen} options={{ title: 'Create Tournament' }} />
        <Stack.Screen name="JoinLeague" component={JoinLeagueScreen} options={{ title: 'Join Tournament' }} />
        <Stack.Screen name="LeagueDetail" component={LeagueDetailScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen
          name="Guess"
          component={GuessScreen}
          options={({ navigation }) => ({
            title: 'Make Your Guess',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="Result"
          component={ResultScreen}
          options={({ navigation }) => ({
            title: 'Round Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen name="Leaderboard" component={LeaderboardScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen
          name="DailyChallenge"
          component={DailyChallengeScreen}
          options={({ navigation }) => ({
            title: 'Daily Ball Challenge',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="DailyResult"
          component={DailyResultScreen}
          options={({ navigation }) => ({
            title: 'Daily Result',
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label="Home" onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen name="WeeklyLeaderboard" component={WeeklyLeaderboardScreen} options={{ title: 'Weekly Leaderboard' }} />
        <Stack.Screen name="FriendProfile" component={FriendProfileScreen} options={({ route }) => ({ title: `@${route.params.username}` })} />
        <Stack.Screen name="ScanFriendCode" component={ScanFriendCodeScreen} options={{ title: 'Scan friend code' }} />
      </Stack.Navigator>
    </NavigationContainer>
  );
}
