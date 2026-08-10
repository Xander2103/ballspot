/**
 * Minimal runner: pure-TS utils only (no React Native imports). RN component
 * testing is deliberately out of scope — the app is verified via tsc + device.
 */
module.exports = {
  testEnvironment: 'node',
  roots: ['<rootDir>/src/utils'],
  transform: {
    '^.+\\.ts$': ['ts-jest', { tsconfig: { jsx: 'react-jsx' } }],
  },
};
