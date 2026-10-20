import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, ImageSourcePropType } from 'react-native';
import { Image } from 'expo-image';
import Svg, { Path } from 'react-native-svg';
import { useTheme } from '../../theme/useTheme';
import { ThemeTokens } from '../../theme/themes';
import { spacing } from '../../theme/spacing';
import { CardGradient } from './CardGradient';

interface Props {
  title: string;
  subtitle: string;
  image: ImageSourcePropType;
  /** Gradient id, unique on the screen. */
  id: string;
  testID?: string;
  onPress: () => void;
}

/**
 * Secondary game mode tile (Challenge Packs, Tournaments). Two of these sit
 * side by side under the Daily card; the parent row stretches them to the
 * same height, and the arrow is pinned to the bottom so both stay aligned
 * whatever the title length or text size.
 */
export function ModeCard({ title, subtitle, image, id, testID, onPress }: Props) {
  const { theme } = useTheme();
  const styles = createStyles(theme);

  return (
    <TouchableOpacity
      style={styles.card}
      testID={testID}
      activeOpacity={0.85}
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${subtitle}`}
    >
      <View style={styles.imageWrap} pointerEvents="none">
        <Image source={image} style={StyleSheet.absoluteFill} contentFit="cover" accessible={false} />
        <CardGradient id={id} color={theme.surface} start={0.4} />
      </View>
      <View style={styles.body}>
        {/* One line, shrinking instead of breaking mid-word: long single-word
            titles ("Uitdagingspakketten") would otherwise wrap letter by letter. */}
        <Text style={styles.title} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.6} maxFontSizeMultiplier={1.5}>
          {title}
        </Text>
        <View style={styles.footer}>
          <Text style={styles.subtitle} maxFontSizeMultiplier={1.5}>{subtitle}</Text>
          <View style={styles.arrow}>
            <Svg width={16} height={16} viewBox="0 0 24 24">
              <Path d="M9 5l7 7-7 7" stroke={theme.text} strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round" fill="none" />
            </Svg>
          </View>
        </View>
      </View>
    </TouchableOpacity>
  );
}

function createStyles(theme: ThemeTokens) {
  return StyleSheet.create({
    card: {
      flex: 1,
      backgroundColor: theme.surface, borderRadius: 18, overflow: 'hidden',
      borderWidth: 1, borderColor: theme.border,
    },
    // Artwork is ~1.85:1; a slightly taller frame crops only the edges.
    imageWrap: { width: '100%', aspectRatio: 1.6 },
    body: { flex: 1, paddingHorizontal: spacing.sm + 4, paddingBottom: spacing.sm + 4, marginTop: -22 },
    title: {
      fontSize: 17, fontWeight: '800', color: theme.text,
      textShadowColor: 'rgba(0,0,0,0.45)', textShadowOffset: { width: 0, height: 1 }, textShadowRadius: 5,
    },
    footer: { flex: 1, flexDirection: 'row', alignItems: 'flex-end', gap: spacing.sm, marginTop: 4 },
    subtitle: { flex: 1, alignSelf: 'flex-start', fontSize: 13, lineHeight: 17, color: theme.textSecondary },
    arrow: {
      width: 32, height: 32, borderRadius: 16,
      alignItems: 'center', justifyContent: 'center',
      backgroundColor: theme.surfaceElevated, borderWidth: 1, borderColor: theme.border,
    },
  });
}
