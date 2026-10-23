import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ActivityIndicator } from 'react-native';
import { useTheme } from '../../theme/useTheme';
import type { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { useI18n } from '../../i18n';
import { Avatar } from '../Avatar';
import { LineIcon } from '../LineIcon';
import type { PlayerRank } from '../../types/auth';

const AVATAR = 72;

interface Props {
  name?: string | null;
  username?: string | null;
  avatarUrl?: string | null;
  /** Null while stats are loading or failed: the pill is simply absent. */
  rank: PlayerRank | null;
  uploading: boolean;
  onChangePhoto: () => void;
}

/**
 * Compact identity row: avatar with a camera badge (tap = change photo),
 * name, handle and the current rank as a small pill (gold level box + rank
 * name; the accessibility label spells out "Level N"). The gear that opens
 * Settings lives in the navigation header, not here.
 */
export function ProfileHeader({ name, username, avatarUrl, rank, uploading, onChangePhoto }: Props) {
  const { theme } = useTheme();
  const { t } = useI18n();
  const styles = createStyles(theme);

  return (
    <View style={styles.row}>
      <TouchableOpacity
        onPress={onChangePhoto}
        disabled={uploading}
        activeOpacity={0.8}
        accessibilityRole="button"
        accessibilityLabel={t('profile.screen.changePhoto')}
        accessibilityState={{ busy: uploading, disabled: uploading }}
        style={styles.avatarWrap}
        testID="profile-change-photo"
      >
        <Avatar uri={avatarUrl} name={name} size={AVATAR} />
        <View style={styles.badge}>
          {uploading
            ? <ActivityIndicator color={theme.onPrimary} size="small" />
            : <LineIcon name="camera" color={theme.onPrimary} size={14} strokeWidth={2} />}
        </View>
      </TouchableOpacity>

      <View style={styles.identity}>
        <Text style={styles.name} numberOfLines={1} maxFontSizeMultiplier={1.3}>{name ?? '—'}</Text>
        <Text style={styles.handle} numberOfLines={1} maxFontSizeMultiplier={1.3}>@{username ?? '—'}</Text>
        {rank ? (
          <View
            style={styles.rankPill}
            accessibilityLabel={`${rank.name}, ${t('profile.header.level', { level: rank.level })}`}
            testID="profile-rank-pill"
          >
            <View style={styles.levelBox}>
              <Text style={styles.levelText} maxFontSizeMultiplier={1.2}>{rank.level}</Text>
            </View>
            <Text style={styles.rankName} numberOfLines={1} maxFontSizeMultiplier={1.3}>{rank.name}</Text>
          </View>
        ) : null}
      </View>
    </View>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md - 2 },
    avatarWrap: { width: AVATAR, height: AVATAR },
    badge: {
      position: 'absolute', right: -2, bottom: -2, width: 26, height: 26, borderRadius: 13,
      backgroundColor: theme.primary, borderWidth: 2, borderColor: theme.background,
      alignItems: 'center', justifyContent: 'center',
    },
    identity: { flex: 1, minWidth: 0 },
    name: { fontSize: 22, fontWeight: '800', color: theme.text },
    handle: { fontSize: 14, color: theme.textSecondary, marginTop: 1 },
    rankPill: {
      flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', gap: 6,
      marginTop: spacing.sm, paddingLeft: 3, paddingRight: 10, paddingVertical: 3, borderRadius: 999,
      backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.border,
    },
    levelBox: { width: 20, height: 20, borderRadius: 6, backgroundColor: theme.gold, alignItems: 'center', justifyContent: 'center' },
    levelText: { fontSize: 11, fontWeight: '900', color: '#1a1400' },
    rankName: { fontSize: 13, fontWeight: '700', color: theme.text, flexShrink: 1 },
  });
}
