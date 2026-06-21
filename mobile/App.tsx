import 'react-native-screens';
import { enableScreens } from 'react-native-screens';
enableScreens();

import React from 'react';
import { AppNavigator } from './src/app/AppNavigator';

export default function App() {
  return <AppNavigator />;
}
