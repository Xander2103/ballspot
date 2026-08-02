import React from 'react';
import { Modal, View, Text, Image, StyleSheet, Pressable, useWindowDimensions } from 'react-native';

interface Props {
  visible: boolean;
  /** Null renders nothing — callers can pass a possibly-missing image safely. */
  imageUri: string | null;
  onClose: () => void;
}

/**
 * Dark full-screen image viewer. Closes on the X button, on a tap anywhere
 * (the image itself is pointer-transparent so taps reach the backdrop), and on
 * the Android hardware back button via Modal's onRequestClose.
 */
export function FullscreenImageViewer({ visible, imageUri, onClose }: Props) {
  const { width, height } = useWindowDimensions();

  if (!imageUri) return null;

  return (
    <Modal
      visible={visible}
      animationType="fade"
      onRequestClose={onClose}
      statusBarTranslucent
      supportedOrientations={['portrait', 'landscape']}
    >
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessibilityRole="button"
          accessibilityLabel="Close fullscreen image"
        />

        <View style={styles.imageWrap} pointerEvents="none">
          <Image source={{ uri: imageUri }} style={{ width, height }} resizeMode="contain" />
        </View>

        <Pressable
          style={styles.closeBtn}
          onPress={onClose}
          hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }}
          accessibilityRole="button"
          accessibilityLabel="Close"
        >
          <Text style={styles.closeText}>✕</Text>
        </Pressable>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: '#000000' },
  imageWrap: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  closeBtn: {
    position: 'absolute',
    top: 44,
    right: 16,
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.14)',
  },
  closeText: { color: '#ffffff', fontSize: 20, fontWeight: '700', lineHeight: 22 },
});
