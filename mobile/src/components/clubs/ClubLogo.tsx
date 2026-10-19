import React, { useState } from 'react';
import { View, Text } from 'react-native';
import { Image } from 'expo-image';
import { useTheme } from '../../theme/useTheme';

interface Props {
  uri: string | null | undefined;
  name: string;
  /** Sport emoji shown when there is no logo (falls back to the club's initials). */
  emoji?: string | null;
  size?: number;
  dimmed?: boolean;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);
  return parts.map((p) => p.charAt(0).toUpperCase()).join('') || '?';
}

/**
 * Club logo (rounded square). expo-image caches it and `recyclingKey` stops a
 * recycled list row from flashing the previous club's logo. A missing or
 * broken logo falls back to the club's initials (or sport emoji).
 */
export function ClubLogo({ uri, name, emoji, size = 48, dimmed = false }: Props) {
  const { theme } = useTheme();
  // Remember WHICH uri failed, so a new logo url gets a fresh try.
  const [failedUri, setFailedUri] = useState<string | null>(null);
  const failed = !!uri && failedUri === uri;
  const radius = Math.round(size * 0.25);
  const box = {
    width: size,
    height: size,
    borderRadius: radius,
    backgroundColor: theme.surfaceElevated,
    borderWidth: 1,
    borderColor: theme.border,
    opacity: dimmed ? 0.55 : 1,
    overflow: 'hidden' as const,
  };

  if (uri && !failed) {
    return (
      <View style={box}>
        <Image
          source={{ uri }}
          style={{ width: '100%', height: '100%' }}
          contentFit="cover"
          transition={150}
          recyclingKey={uri}
          cachePolicy="memory-disk"
          onError={() => setFailedUri(uri)}
        />
      </View>
    );
  }

  return (
    <View style={[box, { alignItems: 'center', justifyContent: 'center' }]}>
      {emoji ? (
        <Text style={{ fontSize: size * 0.5 }}>{emoji}</Text>
      ) : (
        <Text style={{ color: theme.primary, fontSize: size * 0.36, fontWeight: '800' }}>{initials(name)}</Text>
      )}
    </View>
  );
}
