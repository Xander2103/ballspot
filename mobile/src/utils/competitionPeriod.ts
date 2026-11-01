/**
 * Copy for the daily competition, driven by the `period` block the API returns
 * on /daily/stats and /daily/leaderboard/weekly (backend config
 * BALLPICKER_COMPETITION_PERIOD). The app never assumes a period: without a
 * recognised period_type it uses neutral copy ("Leaderboard"), never "Weekly".
 *
 * The route and endpoint keep their historic "weekly" name; the backend window
 * behind them follows the configured period.
 */

import type { CompetitionPeriod } from '../types/daily';

export type CompetitionPeriodType = CompetitionPeriod['period_type'];

type PeriodDescriptor = { period_type?: unknown; period_label?: unknown };

export function competitionPeriodType(period?: PeriodDescriptor | null): CompetitionPeriodType | null {
  const type = period?.period_type;
  return type === 'weekly' || type === 'monthly' ? type : null;
}

/** Translation keys for the leaderboard title, the button leading to it and the score line. */
export function competitionCopyKeys(type?: CompetitionPeriodType | null): {
  title: string;
  viewLeaderboard: string;
  points: string;
} {
  const base = `daily.competition.${type ?? 'neutral'}`;
  return {
    title: `${base}.title`,
    viewLeaderboard: `${base}.viewLeaderboard`,
    points: `${base}.points`,
  };
}

/**
 * Label for the period bar ("Monthly · 1 Nov – 30 Nov"). The API's generic
 * English labels are translated from period_type, so a stale "Weekly" can never
 * contradict the real period. A custom BALLPICKER_COMPETITION_LABEL ("Season")
 * is an admin's deliberate wording and is shown as is.
 */
export function competitionPeriodLabel(
  t: (key: string) => string,
  period?: PeriodDescriptor | null,
  legacyLabel?: string | null,
): string {
  const rawLabel = typeof period?.period_label === 'string' ? period.period_label : legacyLabel;
  const label = rawLabel?.trim() ?? '';
  const isGeneric = /^(weekly|monthly)$/i.test(label);
  const type = competitionPeriodType(period);

  if (label && !isGeneric) return label;
  if (type) return t(`daily.competition.${type}.periodName`);
  return t(competitionCopyKeys(null).title);
}

/**
 * Category names and challenge titles are free text from the admin panel. A
 * value shaped like a code identifier (AboveGoalHeader, above_goal_header,
 * game.result.title) is an internal name, not copy, so it is hidden rather than
 * shown to players. Normal names ("Corner Kicks", "Headers", "PSG") pass.
 */
export function visibleChallengeLabel(value?: string | null): string | null {
  const label = value?.trim();
  if (!label) return null;
  const isIdentifier =
    /^[A-Z]?[a-z0-9]+(?:[A-Z][a-z0-9]*)+$/.test(label) || // PascalCase / camelCase
    /^[A-Za-z0-9]+(?:_[A-Za-z0-9]+)+$/.test(label) || // snake_case
    /^[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9_]+)+$/.test(label); // dotted translation key
  return isIdentifier ? null : label;
}
