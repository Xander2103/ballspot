import React, { useCallback, useEffect, useState } from 'react';
import { NavigationContainer, NavigatorScreenParams, LinkingOptions, createNavigationContainerRef } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { View, ActivityIndicator } from 'react-native';
import { useTheme } from '../theme/useTheme';
import { isThemeName } from '../theme/themes';
import { tokenStorage } from '../storage/tokenStorage';
import { authApi } from '../api/authApi';
import { bootstrapSession, routeForUser, logoutLocally, onSessionInvalid } from '../utils/session';
import { signOut } from './signOut';
import { EmptyState } from '../components/EmptyState';
import { WEB_BASE_URL } from '../utils/urls';

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
import { initLocale, useI18n } from '../i18n';

export type RootStackParamList = {
  /** `reason` shows a one-line notice ("Your session has expired…") after a forced sign-out. */
  Login: { reason?: 'session_expired' | 'account_deleted' } | undefined;
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
  PackResult: { slug: string; packName: string; result: PackGuessResult; imageUrl: string | null; sportSlug?: string | null };
  PackComplete: { slug: string; packName: string; completion?: PackCompletionSummary | null };
  RankOverview: undefined;
  TrophyRoom: undefined;
  CreateLeague: undefined;
  JoinLeague: undefined;
  LeagueDetail: { leagueId: number; leagueName: string };
  Guess: { leagueId: number; roundId: number; leagueName: string };
  Result: { roundId: number; leagueId: number; imageUrl: string; leagueName: string; categoryName?: string | null; challengeTitle?: string | null; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null; tournamentCompletion?: TournamentCompletion; sportSlug?: string | null };
  Leaderboard: { leagueId: number; leagueName: string };
  DailyChallenge: { dailyChallengeId: number };
  DailyResult: { dailyChallengeId: number; newBadges?: Badge[]; rankProgress?: RankProgress; rankUp?: RankUp | null };
  WeeklyLeaderboard: undefined;
  FriendProfile: { userId: number; username: string };
  ScanFriendCode: undefined;
};

const Stack = createNativeStackNavigator<RootStackParamList>();

/**
 * Module-level ref so a dead session detected by the API client (any screen,
 * any request) can reset the whole app to Login without a navigation prop.
 */
export const navigationRef = createNavigationContainerRef<RootStackParamList>();

const WEB_BASE = WEB_BASE_URL;

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
  const { t } = useI18n();
  const [initialRoute, setInitialRoute] = useState<keyof RootStackParamList | null>(null);
  const [initialParams, setInitialParams] = useState<RootStackParamList['Login']>(undefined);
  // App-start /me failed for a recoverable reason (offline / 5xx): the stored
  // token may well be fine, so offer Retry + Logout instead of guessing.
  const [recoverable, setRecoverable] = useState(false);

  const resolveInitialRoute = useCallback(async () => {
    setRecoverable(false);
    const result = await bootstrapSession({
      getToken: () => tokenStorage.get(),
      fetchMe: () => authApi.me(),
      clearLocal: signOut,
    });

    if (result.kind === 'ready') {
      const { user } = result;
      // The account's preferred_language wins (fallback order rule 1).
      await initLocale(user.preferred_language).catch(() => {});
      // Apply the server-side theme without re-syncing it back.
      if (user.selected_theme && isThemeName(user.selected_theme)) {
        setTheme(user.selected_theme, { sync: false });
      }
      setInitialRoute(routeForUser(user));
      return;
    }

    // Signed out (no token, or a token the server rejected): stored choice →
    // device language → backend default → en.
    await initLocale(null).catch(() => {});
    if (result.kind === 'signed_out') {
      setInitialParams(result.reason === 'session_invalid' ? { reason: 'session_expired' } : undefined);
      setInitialRoute('Login');
      return;
    }
    setRecoverable(true);
  }, [setTheme]);

  useEffect(() => { resolveInitialRoute(); }, [resolveInitialRoute]);

  // A dead session anywhere in the app (the API client saw a 401 or the
  // account_deleted / session_invalid code): wipe local state and return to
  // Login with a clear message. Never leaves the user in the tab app.
  useEffect(() => onSessionInvalid((reason) => {
    (async () => {
      await signOut();
      const params = { reason: reason === 'account_deleted' ? 'account_deleted' as const : 'session_expired' as const };
      if (navigationRef.isReady()) {
        navigationRef.reset({ index: 0, routes: [{ name: 'Login', params }] });
      } else {
        setRecoverable(false);
        setInitialParams(params);
        setInitialRoute('Login');
      }
    })();
  }), []);

  // Runs after the resolved route has committed, so the native splash hides
  // over real UI rather than the spinner. The recoverable state counts too:
  // it is real UI with real buttons.
  useEffect(() => {
    if (initialRoute !== null || recoverable) onReady?.();
  }, [initialRoute, recoverable, onReady]);

  async function handleStartupLogout() {
    await logoutLocally({ apiLogout: () => authApi.logout(), clearLocal: signOut });
    setRecoverable(false);
    setInitialParams(undefined);
    setInitialRoute('Login');
  }

  const screenOptions = {
    headerStyle: { backgroundColor: theme.surface },
    headerTintColor: theme.text,
    headerTitleStyle: { fontWeight: '700' as const, color: theme.text },
    contentStyle: { backgroundColor: theme.background },
  };

  if (initialRoute === null) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.background, alignItems: 'center', justifyContent: 'center' }}>
        {recoverable ? (
          <EmptyState
            icon="📡"
            title={t('auth.session.title')}
            message={t('auth.session.message')}
            actions={[
              { label: t('common.buttons.retry'), onPress: () => { resolveInitialRoute(); } },
              { label: t('common.buttons.logout'), onPress: () => { handleStartupLogout(); } },
            ]}
          />
        ) : (
          <ActivityIndicator color={theme.primary} size="large" />
        )}
      </View>
    );
  }

  return (
    <NavigationContainer ref={navigationRef} linking={linking}>
      <Stack.Navigator initialRouteName={initialRoute} screenOptions={screenOptions}>
        <Stack.Screen name="Login" component={LoginScreen} initialParams={initialParams} options={{ headerShown: false }} />
        <Stack.Screen name="LoginVerification" component={LoginVerificationScreen} options={{ title: t('nav.titles.verifyLogin') }} />
        <Stack.Screen name="EmailVerification" component={EmailVerificationScreen} options={{ title: t('nav.titles.verifyEmail'), headerLeft: () => null }} />
        <Stack.Screen name="Register" component={RegisterScreen} options={{ title: t('nav.titles.createAccount') }} />
        <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: t('nav.titles.forgotPassword') }} />
        <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: t('nav.titles.resetPassword') }} />
        <Stack.Screen name="Home" component={MainTabs} options={{ headerShown: false }} />
        <Stack.Screen name="SportSelection" component={SportSelectionScreen} options={{ title: t('nav.titles.chooseSport') }} />
        <Stack.Screen name="Packs" component={PacksScreen} options={{ title: t('nav.titles.packs') }} />
        <Stack.Screen name="PackDetail" component={PackDetailScreen} options={({ route }) => ({ title: route.params.name })} />
        <Stack.Screen
          name="PackGuess"
          component={PackGuessScreen}
          options={({ route, navigation }) => ({
            title: route.params.packName,
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.packs')} onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="PackResult"
          component={PackResultScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.packResult'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.packs')} onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen
          name="PackComplete"
          component={PackCompleteScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.packCompleted'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.packs')} onPress={() => goPacks(navigation)} />,
          })}
        />
        <Stack.Screen name="RankOverview" component={RankOverviewScreen} options={{ title: t('nav.titles.allRanks') }} />
        <Stack.Screen name="TrophyRoom" component={TrophyRoomScreen} options={{ title: t('nav.titles.trophyRoom') }} />
        <Stack.Screen name="CreateLeague" component={CreateLeagueScreen} options={{ title: t('nav.titles.createTournament') }} />
        <Stack.Screen name="JoinLeague" component={JoinLeagueScreen} options={{ title: t('nav.titles.joinTournament') }} />
        <Stack.Screen name="LeagueDetail" component={LeagueDetailScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen
          name="Guess"
          component={GuessScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.makeYourGuess'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.home')} onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="Result"
          component={ResultScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.roundResult'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.home')} onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen name="Leaderboard" component={LeaderboardScreen} options={({ route }) => ({ title: route.params.leagueName })} />
        <Stack.Screen
          name="DailyChallenge"
          component={DailyChallengeScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.dailyChallenge'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.home')} onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen
          name="DailyResult"
          component={DailyResultScreen}
          options={({ navigation }) => ({
            title: t('nav.titles.dailyResult'),
            gestureEnabled: false,
            headerBackVisible: false,
            headerLeft: () => <HeaderExitButton label={t('nav.exit.home')} onPress={() => goHome(navigation)} />,
          })}
        />
        <Stack.Screen name="WeeklyLeaderboard" component={WeeklyLeaderboardScreen} options={{ title: t('nav.titles.weeklyLeaderboard') }} />
        <Stack.Screen name="FriendProfile" component={FriendProfileScreen} options={({ route }) => ({ title: `@${route.params.username}` })} />
        <Stack.Screen name="ScanFriendCode" component={ScanFriendCodeScreen} options={{ title: t('nav.titles.scanFriendCode') }} />
      </Stack.Navigator>
    </NavigationContainer>
  );
}
