import React from 'react';
import { View, StyleSheet, SafeAreaView, ScrollView, ViewStyle } from 'react-native';
import { useTheme } from '../theme/useTheme';

interface Props {
  children: React.ReactNode;
  scroll?: boolean;
  style?: ViewStyle;
  padding?: boolean;
}

export function Screen({ children, scroll, style, padding = true }: Props) {
  const { theme } = useTheme();
  const bg = { backgroundColor: theme.background };

  const content = (
    <View style={[styles.inner, padding && styles.padding, style]}>
      {children}
    </View>
  );

  return (
    <SafeAreaView style={[styles.safe, bg]}>
      {scroll ? (
        <ScrollView
          style={[styles.scroll, bg]}
          contentContainerStyle={[styles.inner, padding && styles.padding, style]}
          keyboardShouldPersistTaps="handled"
        >
          {children}
        </ScrollView>
      ) : content}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  scroll: { flex: 1 },
  inner: { flex: 1 },
  padding: { padding: 20 },
});
