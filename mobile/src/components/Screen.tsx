import React from 'react';
import { View, StyleSheet, SafeAreaView, ScrollView, ViewStyle, Platform } from 'react-native';
import { useTheme } from '../theme/useTheme';

interface Props {
  children: React.ReactNode;
  scroll?: boolean;
  style?: ViewStyle;
  padding?: boolean;
  /**
   * Keep focused inputs above the iOS keyboard. On by default for scrolling
   * screens (forms). iOS only: Android resizes the window itself
   * (softwareKeyboardLayoutMode "resize") and web has no soft keyboard inset.
   */
  keyboardSafe?: boolean;
}

/** Room under the last field so it can scroll clear of the keyboard + accessory bar. */
const KEYBOARD_BOTTOM_PADDING = 96;

export function Screen({ children, scroll, style, padding = true, keyboardSafe = true }: Props) {
  const { theme } = useTheme();
  const bg = { backgroundColor: theme.background };
  const iosKeyboard = Platform.OS === 'ios' && !!scroll && keyboardSafe;

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
          // flexGrow, not flex: `flex: 1` on a content container pins it to the
          // viewport height, so anything taller is clipped instead of scrolling.
          // flexGrow still fills the screen when the content is short.
          contentContainerStyle={[
            styles.scrollInner,
            padding && styles.padding,
            iosKeyboard && styles.keyboardPadding,
            style,
          ]}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
          // iOS: UIKit grows the bottom content inset by the keyboard height and
          // scrolls the focused TextInput into view, so the password / beta
          // code fields and the submit button stay reachable on small phones.
          // (Deliberately no KeyboardAvoidingView on top — the two together
          // double the offset and leave a blank band above the keyboard.)
          automaticallyAdjustKeyboardInsets={iosKeyboard}
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
  scrollInner: { flexGrow: 1 },
  padding: { padding: 20 },
  keyboardPadding: { paddingBottom: KEYBOARD_BOTTOM_PADDING },
});
