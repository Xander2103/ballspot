import React, { useEffect, useState } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { View, ActivityIndicator } from 'react-native';
import { colors } from '../theme/colors';
import { tokenStorage } from '../storage/tokenStorage';

import { LoginScreen } from '../screens/LoginScreen';
import { RegisterScreen } from '../screens/RegisterScreen';
import { ForgotPasswordScreen } from '../screens/ForgotPasswordScreen';
import { ResetPasswordScreen } from '../screens/ResetPasswordScreen';
import { HomeScreen } from '../screens/HomeScreen';
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
  Register: undefined;
  ForgotPassword: undefined;
  ResetPassword: { email?: string; token?: string } | undefined;
  Home: undefined;
  Profile: undefined;
  CreateLeague: undefined;
  JoinLeague: undefined;
  LeagueDetail: { leagueId: number; leagueName: string };
  Guess: { leagueId: number; roundId: number; leagueName: string };
  Result: { roundId: number; leagueId: number; imageUrl: string; leagueName: string; categoryName?: string | null };
  Leaderboard: { leagueId: number; leagueName: string };
  DailyChallenge: { dailyChallengeId: number };
  DailyResult: { dailyChallengeId: number };
  WeeklyLeaderboard: undefined;
};

const Stack = createNativeStackNavigator<RootStackParamList>();

const screenOptions = {
  headerStyle: { backgroundColor: colors.surface },
  headerTintColor: colors.text,
  headerTitleStyle: { fontWeight: '700' as const, color: colors.text },
  contentStyle: { backgroundColor: colors.background },
};

export function AppNavigator() {
  const [isLoggedIn, setIsLoggedIn] = useState<boolean | null>(null);

  useEffect(() => {
    tokenStorage.get().then((token) => setIsLoggedIn(!!token)).catch(() => setIsLoggedIn(false));
  }, []);

  if (isLoggedIn === null) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.background, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      <Stack.Navigator initialRouteName={isLoggedIn ? 'Home' : 'Login'} screenOptions={screenOptions}>
        <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        <Stack.Screen name="Register" component={RegisterScreen} options={{ title: 'Create Account' }} />
        <Stack.Screen name="ForgotPassword" component={ForgotPasswordScreen} options={{ title: 'Forgot Password' }} />
        <Stack.Screen name="ResetPassword" component={ResetPasswordScreen} options={{ title: 'Reset Password' }} />
        <Stack.Screen name="Home" component={HomeScreen} options={{ title: 'BallSpot', headerLeft: () => null }} />
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
