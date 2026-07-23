import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, StyleSheet, FlatList, ActivityIndicator, TouchableOpacity, Image } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../app/AppNavigator';
import { Screen } from '../components/Screen';
import { packApi } from '../api/packApi';
import { useTheme } from '../theme/useTheme';
import type { ThemeTokens } from '../theme/themes';
import { spacing } from '../theme/spacing';
import type { ChallengePackSummary } from '../types/pack';

type Props = NativeStackScreenProps<RootStackParamList, 'Packs'>;

export function PacksScreen({ navigation }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  const [packs, setPacks] = useState<ChallengePackSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await packApi.list();
      setPacks(res.data ?? []);
    } catch {
      setError('Could not load packs. Pull to retry.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  if (loading) {
    return (
      <Screen>
        <View style={styles.center}><ActivityIndicator color={theme.primary} size="large" /></View>
      </Screen>
    );
  }

  return (
    <Screen padding={false}>
      <FlatList<ChallengePackSummary>
        data={packs}
        keyExtractor={(p) => String(p.id)}
        contentContainerStyle={styles.listContent}
        onRefresh={load}
        refreshing={loading}
        ListHeaderComponent={
          <View style={styles.header}>
            <Text style={styles.title}>Challenge Packs</Text>
            <Text style={styles.subtitle}>Play themed sets of challenges.</Text>
          </View>
        }
        renderItem={({ item }) => <PackCard pack={item} styles={styles} theme={theme}
          onPress={() => navigation.navigate('PackDetail', { slug: item.slug, name: item.name })} />}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyEmoji}>📦</Text>
            <Text style={styles.emptyText}>{error || 'No packs available yet.'}</Text>
          </View>
        }
      />
    </Screen>
  );
}

function PackCard({
  pack, styles, theme, onPress,
}: { pack: ChallengePackSummary; styles: Styles; theme: ThemeTokens; onPress: () => void }) {
  const accent = pack.sport?.primary_color ?? theme.primary;
  return (
    <TouchableOpacity style={styles.card} activeOpacity={0.85} onPress={onPress}>
      <View style={[styles.cover, { backgroundColor: accent + '22' }]}>
        {pack.cover_image_url ? (
          <Image source={{ uri: pack.cover_image_url }} style={styles.coverImg} resizeMode="cover" />
        ) : (
          <Text style={styles.coverEmoji}>{pack.sport?.emoji ?? '⚽'}</Text>
        )}
        {pack.is_featured ? <View style={[styles.featured, { backgroundColor: accent }]}><Text style={styles.featuredText}>★ Featured</Text></View> : null}
      </View>
      <View style={styles.cardBody}>
        <Text style={styles.cardTitle} numberOfLines={1}>{pack.name}</Text>
        {pack.description ? <Text style={styles.cardDesc} numberOfLines={2}>{pack.description}</Text> : null}
        <View style={styles.metaRow}>
          <Text style={styles.metaChip}>{pack.sport?.name ?? 'All sports'}</Text>
          <Text style={styles.metaChip}>{pack.challenge_count} {pack.challenge_count === 1 ? 'challenge' : 'challenges'}</Text>
          {pack.difficulty ? <Text style={styles.metaChip}>{cap(pack.difficulty)}</Text> : null}
          {pack.progress?.status === 'completed'
            ? <Text style={[styles.metaChip, styles.completedChip]}>✓ Completed</Text>
            : pack.progress?.status === 'active'
              ? <Text style={[styles.metaChip, styles.activeChip]}>In progress · {pack.progress.completed_count}/{pack.progress.total_challenges}</Text>
              : null}
        </View>
      </View>
    </TouchableOpacity>
  );
}

function cap(s: string): string {
  return s.length ? s[0].toUpperCase() + s.slice(1) : s;
}

type Styles = ReturnType<typeof createStyles>;

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 240 },
    listContent: { padding: spacing.lg, paddingBottom: spacing.xxl },
    header: { marginBottom: spacing.md },
    title: { fontSize: 24, fontWeight: '800', color: theme.text },
    subtitle: { fontSize: 14, color: theme.textMuted, marginTop: 2 },
    card: {
      backgroundColor: theme.surface,
      borderRadius: 16,
      borderWidth: 1,
      borderColor: theme.border,
      overflow: 'hidden',
      marginBottom: spacing.md,
    },
    cover: { height: 120, alignItems: 'center', justifyContent: 'center' },
    coverImg: { width: '100%', height: '100%' },
    coverEmoji: { fontSize: 48 },
    featured: { position: 'absolute', top: spacing.sm, right: spacing.sm, paddingHorizontal: spacing.sm, paddingVertical: 3, borderRadius: 999 },
    featuredText: { color: '#fff', fontSize: 11, fontWeight: '700' },
    cardBody: { padding: spacing.md },
    cardTitle: { fontSize: 17, fontWeight: '700', color: theme.text },
    cardDesc: { fontSize: 13, color: theme.textMuted, marginTop: 2 },
    metaRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.sm },
    metaChip: {
      fontSize: 12, color: theme.textSecondary,
      backgroundColor: theme.surfaceElevated,
      borderRadius: 999, paddingHorizontal: spacing.sm, paddingVertical: 3,
      overflow: 'hidden',
    },
    completedChip: { color: theme.success, fontWeight: '700', backgroundColor: theme.success + '1a' },
    activeChip: { color: theme.primary, fontWeight: '700', backgroundColor: theme.primary + '1a' },
    empty: { alignItems: 'center', paddingVertical: spacing.xxl },
    emptyEmoji: { fontSize: 40, marginBottom: spacing.sm },
    emptyText: { fontSize: 15, color: theme.textMuted, textAlign: 'center' },
  });
}
