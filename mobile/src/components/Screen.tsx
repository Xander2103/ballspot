import React from 'react';
import { View, StyleSheet, SafeAreaView, ScrollView, ViewStyle } from 'react-native';
import { colors } from '../theme/colors';

interface Props {
  children: React.ReactNode;
  scroll?: boolean;
  style?: ViewStyle;
  padding?: boolean;
}

export function Screen({ children, scroll, style, padding = true }: Props) {
  const content = (
    <View style={[styles.inner, padding && styles.padding, style]}>
      {children}
    </View>
  );

  return (
    <SafeAreaView style={styles.safe}>
      {scroll ? (
        <ScrollView
          style={styles.scroll}
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
  safe: { flex: 1, backgroundColor: colors.background },
  scroll: { flex: 1, backgroundColor: colors.background },
  inner: { flex: 1 },
  padding: { padding: 20 },
});
