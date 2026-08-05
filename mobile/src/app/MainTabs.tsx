import React from 'react';
import { createBottomTabNavigator, BottomTabScreenProps } from '@react-navigation/bottom-tabs';
import type { CompositeScreenProps } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTheme } from '../theme/useTheme';
import { TabIcon, TabIconName } from '../components/TabIcon';
import { formatBadgeCount } from '../components/CollapsibleSection';
import { FriendRequestsProvider, useFriendRequests } from './friendRequests';
import { HomeScreen } from '../screens/HomeScreen';
import { TournamentsScreen } from '../screens/TournamentsScreen';
import { FriendsScreen } from '../screens/FriendsScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import type { RootStackParamList } from './AppNavigator';

export type MainTabParamList = {
  Play: undefined;
  Tournaments: undefined;
  /** `scannedCode` is how ScanFriendCodeScreen hands a scanned code back. */
  Friends: { scannedCode?: string } | undefined;
  Profile: undefined;
};

/**
 * Props for a screen living inside the tab bar: its own tab route plus the
 * parent stack, so tab screens can still navigate to detail/game screens.
 */
export type MainTabScreenProps<T extends keyof MainTabParamList> = CompositeScreenProps<
  BottomTabScreenProps<MainTabParamList, T>,
  NativeStackScreenProps<RootStackParamList>
>;

const Tab = createBottomTabNavigator<MainTabParamList>();

function icon(name: TabIconName) {
  return ({ color, focused }: { color: string; focused: boolean }) => (
    <TabIcon name={name} color={color} focused={focused} />
  );
}

function Tabs() {
  const { theme } = useTheme();
  const insets = useSafeAreaInsets();
  const { incomingCount } = useFriendRequests();

  // Give labels breathing room: a slightly taller bar plus explicit padding so
  // the 11pt labels are never clipped by the home-indicator inset on iPhone.
  const bottomPad = Math.max(insets.bottom, 8);

  return (
    <Tab.Navigator
      screenOptions={{
        headerStyle: { backgroundColor: theme.surface },
        headerTintColor: theme.text,
        headerTitleStyle: { fontWeight: '700' as const, color: theme.text },
        sceneStyle: { backgroundColor: theme.background },
        tabBarStyle: {
          backgroundColor: theme.surface,
          borderTopColor: theme.border,
          height: 56 + bottomPad,
          paddingTop: 6,
          paddingBottom: bottomPad,
        },
        tabBarActiveTintColor: theme.primary,
        tabBarInactiveTintColor: theme.textMuted,
        tabBarLabelStyle: { fontSize: 11, lineHeight: 14, fontWeight: '600' as const },
      }}
    >
      <Tab.Screen
        name="Play"
        component={HomeScreen}
        options={{ headerShown: false, tabBarIcon: icon('play') }}
      />
      <Tab.Screen
        name="Tournaments"
        component={TournamentsScreen}
        options={{ title: 'Tournaments', tabBarIcon: icon('tournaments') }}
      />
      <Tab.Screen
        name="Friends"
        component={FriendsScreen}
        options={{
          title: 'Friends',
          tabBarIcon: icon('friends'),
          tabBarBadge: incomingCount > 0 ? formatBadgeCount(incomingCount) : undefined,
          tabBarBadgeStyle: {
            backgroundColor: theme.danger,
            color: '#ffffff',
            fontSize: 10,
            fontWeight: '800' as const,
            lineHeight: 16,
          },
        }}
      />
      <Tab.Screen
        name="Profile"
        component={ProfileScreen}
        options={{ title: 'Profile', tabBarIcon: icon('profile') }}
      />
    </Tab.Navigator>
  );
}

export function MainTabs() {
  return (
    <FriendRequestsProvider>
      <Tabs />
    </FriendRequestsProvider>
  );
}
