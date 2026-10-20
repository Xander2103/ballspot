import { noteServerDate, serverNow, resetServerClockForTests } from '../serverClock';

afterEach(() => resetServerClockForTests());

describe('serverClock', () => {
  it('follows the server when the device clock is off', () => {
    const received = Date.now();
    noteServerDate(new Date(received + 3 * 3600_000).toUTCString(), received);
    expect(serverNow() - Date.now()).toBeGreaterThan(3 * 3600_000 - 2000);
    expect(serverNow() - Date.now()).toBeLessThan(3 * 3600_000 + 2000);
  });

  it('ignores sub-second header rounding and transfer time', () => {
    const received = Date.now();
    noteServerDate(new Date(received - 900).toUTCString(), received);
    expect(Math.abs(serverNow() - Date.now())).toBeLessThan(50);
  });

  it('keeps the last good offset when a header is missing or unreadable', () => {
    const received = Date.now();
    noteServerDate(new Date(received - 600_000).toUTCString(), received);
    noteServerDate(null);
    noteServerDate('yesterday-ish');
    expect(Date.now() - serverNow()).toBeGreaterThan(590_000);
  });
});
