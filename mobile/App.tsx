import React from 'react';
import { enableScreens } from 'react-native-screens';
import { AppNavigator } from './src/app/AppNavigator';

enableScreens();

export default function App() {
  return <AppNavigator />;
}
