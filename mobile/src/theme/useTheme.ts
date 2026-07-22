import { useContext } from 'react';
import { ThemeContext } from './ThemeProvider';

/** Access the active theme tokens and theme controls. */
export function useTheme() {
  return useContext(ThemeContext);
}
