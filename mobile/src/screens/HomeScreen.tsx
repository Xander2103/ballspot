import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, FlatList, TouchableOpacity } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { AppButton } from '../components/AppButton';
import { leagueApi } from '../api/leagueApi';
import { authApi } from '../api/authApi';
import { tokenStorage } from '../storage/tokenStorage';
import { colors } from '../theme/colors';
import { spacing } from '../theme/spacing';
import { League } from '../types/league';
import { User } from '../types/auth';

type Props = NativeStackScreenProps<RootStackParamList, 'Home'>;

export function HomeScreen({ navigation }: Props) {
  const [leagues, setLeagues] = useState<League[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const [me, list] = await Promise.all([authApi.me(), leagueApi.list()]);
      setUser(me);
      setLeagues(Array.isArray(list) ? list : []);
    } catch {
      // silent
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    const unsub = navigation.addListener('focus', load);
    return unsub;
  }, [navigation, load]);

  async function handleLogout() {
    try { await authApi.logout(); } catch {}
    await tokenStorage.remove();
    navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
  }

  function renderLeague({ item }: { item: League }) {
    return (
      <TouchableOpacity
        style={styles.card}
        onPress={() => navigation.navigate('LeagueDetail', { leagueId: item.id, leagueName: item.name })}
        activeOpacity={0.8}
      >
        <Text style={styles.leagueName}>{item.name}</Text>
        <Text style={styles.leagueMeta}>Code: {item.join_code} · {item.members_count} players · {item.total_rounds} rounds</Text>
      </TouchableOpacity>
    );
  }

  return (
    <Screen padding={false}>
      <View style={styles.topBar}>
        <View>
          <Text style={styles.greeting}>Hey, {user?.name || '…'} 👋</Text>
          <Text style={styles.sub}>@{user?.username || '…'}</Text>
        </View>
        <AppButton title="Logout" onPress={handleLogout} variant="secondary" style={styles.logoutBtn} />
      </View>
      <FlatList
        data={leagues}
        keyExtractor={(l) => String(l.id)}
        renderItem={renderLeague}
        contentContainerStyle={styles.list}
        ListEmptyComponent={!loading ? <Text style={styles.empty}>No leagues yet. Create or join one!</Text> : null}
        ListFooterComponent={
          <View style={styles.actions}>
            <AppButton title="+ Create League" onPress={() => navigation.navigate('CreateLeague')} style={styles.actionBtn} />
            <AppButton title="Join League" onPress={() => navigation.navigate('JoinLeague')} variant="secondary" />
          </View>
        }
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  topBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: spacing.md, backgroundColor: colors.surface },
  greeting: { fontSize: 18, fontWeight: '700', color: colors.text },
  sub: { fontSize: 13, color: colors.textSecondary },
  logoutBtn: { height: 36, paddingHorizontal: spacing.md },
  list: { padding: spacing.md, gap: spacing.sm },
  card: { backgroundColor: colors.surface, borderRadius: 12, padding: spacing.md, borderWidth: 1, borderColor: colors.border },
  leagueName: { fontSize: 16, fontWeight: '700', color: colors.text, marginBottom: 4 },
  leagueMeta: { fontSize: 13, color: colors.textSecondary },
  empty: { textAlign: 'center', color: colors.textSecondary, padding: spacing.xl },
  actions: { gap: spacing.sm, marginTop: spacing.md },
  actionBtn: { marginBottom: 0 },
});
