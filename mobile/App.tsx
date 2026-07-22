import React from 'react';
import { enableScreens } from 'react-native-screens';
import { AppNavigator } from './src/app/AppNavigator';
import { ThemeProvider } from './src/theme/ThemeProvider';

enableScreens();

export default function App() {
  return (
    <ThemeProvider>
      <AppNavigator />
    </ThemeProvider>
  );
}
