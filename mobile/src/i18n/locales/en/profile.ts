/**
 * Profile tab: identity, progression (rank card / rank ladder), Trophy Room,
 * history, theme picker, stats, account & security and account deletion.
 */
export const profile = {
  screen: {
    loadErrorTitle: "Couldn't load your profile",
    changePhoto: 'Change photo',
    photoPermission: 'Photo permission is needed to choose an avatar.',
    photoError: 'Could not update your photo. Please try again.',
    statsError: "Couldn't load your rank and stats.",
    viewAllRanks: 'View all ranks',
    viewAllRanksSubtitle: 'Every rank, your progress and your recent XP.',
    trophyRoom: 'Trophy Room',
    trophyRoomSubtitle: 'Badges, achievements and top finishes.',
    history: 'History',
    historySummary: '{{count}} finished',
    historyError: "Couldn't load your history.",
    historyEmpty: 'No finished tournaments yet.',
    sportNotChosen: 'Not chosen yet',
    appTheme: 'App theme',
    streakSummary: '🔥 {{days}} day streak',
    notifications: 'Notifications',
    deleteAccount: 'Delete account',
    version: 'v{{version}}',
    credit: 'BallPicker is created by Van Malder Studio.',
  },
  /** Share BallPicker (rewarded, bounded) + Rate BallPicker (never rewarded). */
  growth: {
    shareApp: 'Share BallPicker',
    shareAppSubtitle: 'Invite friends to play.',
    shareAppXp: 'Invite friends · +{{xp}} XP',
    shareAppClaimed: 'Invite friends · reward claimed ✓',
    shareAppMessage: 'Think you can spot the ball? Play BallPicker with me ⚽🎯',
    shareThanks: 'Thanks for sharing BallPicker!',
    shareXpEarned: '+{{xp}} XP earned for sharing!',
    shareError: 'Could not open the share sheet. Please try again.',
    rateApp: 'Rate BallPicker',
    rateAppSubtitle: 'Rate the app in the store.',
    rateUnavailable: 'Rating is not available on this device yet.',
  },
  /** Labels shared by the Profile stat lists and the friend profile grid. */
  stats: {
    tournaments: 'Tournaments',
    completed: 'Completed',
    guesses: 'Guesses',
    totalScore: 'Total score',
    averageScore: 'Average score',
    currentStreak: 'Current streak',
    bestDetail: 'best {{best}}',
    played: 'Played',
    bestScore: 'Best score',
  },
  /** Display labels for src/theme/themes.ts slugs (slugs never change). */
  themes: {
    classic: { label: 'Classic', description: 'The original BallPicker look.' },
    tournament_blue: { label: 'Tournament Blue', description: 'European night — navy, turquoise & red.' },
    pitch_green: { label: 'Pitch Green', description: 'Stadium grass focus.' },
    sunset_orange: { label: 'Sunset Orange', description: 'Warm & energetic.' },
    high_contrast: { label: 'High Contrast', description: 'Maximum readability.' },
  },
  account: {
    title: 'Account & security',
    languageHint: 'Used for emails and app text.',
    languageError: 'Could not save your language. Please try again.',
  },
  security: {
    twoFactorLabel: 'Two-factor login',
    twoFactorOnHint: 'Each login asks for a 6-digit code we email you.',
    twoFactorOffHint: 'Off: email and password sign you straight in.',
    twoFactorError: 'Could not update two-factor login. Please try again.',
  },
  delete: {
    title: 'Delete account?',
    message: 'This will remove your account access and anonymize your profile. This action cannot be undone.',
    confirm: 'Delete account',
    error: 'We could not delete your account right now. Please try again in a moment or contact support.',
    doneTitle: 'Account deleted',
    doneMessage: 'Your account has been deleted and your personal details removed. You can create a new account with the same email at any time.',
  },
  /** RankCard (personal progression card). */
  rankCard: {
    maxLevel: '  ·  Max level',
    level: '  ·  Level {{level}}',
    xp: '{{xp}} XP',
    maxReached: 'No next rank — you reached the top.',
    toNext: '{{xp}} XP to {{rank}} · {{pct}}%',
  },
  /** RankOverviewScreen (full rank ladder). */
  ranks: {
    loadError: 'Could not load ranks. Please try again.',
    heading: 'Rank ladder',
    subheadingWithXp: 'You have {{xp}} XP — every rank and the XP needed to reach it.',
    subheading: 'Every rank and the XP needed to reach it.',
    levelLabel: '  ·  Level {{level}}',
    minXp: '{{xp}} XP',
    maxRank: 'Max rank',
    current: 'Current rank',
    completed: 'Completed',
    xpNeeded: '{{xp}} XP needed',
    reached: 'Reached',
    recentXp: 'Recent XP',
  },
  trophyRoom: {
    title: 'Trophy Room',
    earnedCount: '{{earned}} / {{total}} earned',
    loadError: 'Could not load badges.',
    locked: 'Locked',
    badgeA11y: '{{name}}, {{state}}',
    stateEarned: 'earned',
    stateLocked: 'locked',
    tournamentTrophies: 'Tournament trophies',
    noTournamentTrophies: 'No tournament trophies yet.',
    packTrophies: 'Pack trophies',
    noPackTrophies: 'No completed packs yet.',
    competitionTrophies: 'Competition trophies',
    noCompetitionTrophies: 'No competition trophies yet.',
    earnedOn: 'Earned {{date}}',
    earned: 'Earned',
    notEarned: 'Not earned yet',
    weeklyCompetition: 'Weekly Competition',
    monthlyCompetition: 'Monthly Competition',
    packFallback: 'Challenge pack',
    perfect: ' — Perfect!',
    place: '{{ordinal}} place',
    placeWithName: '{{place}} — {{name}}',
    ordinal: {
      one: '{{n}}st',
      two: '{{n}}nd',
      few: '{{n}}rd',
      other: '{{n}}th',
    },
  },
  /** ProfileHistoryCard (compact finished-tournament rows). */
  history: {
    fallbackName: 'Tournament',
    placement: '#{{placement}}',
    of: ' of {{total}}',
    pts: ' · {{score}} pts',
    rounds: ' · {{count}} rounds',
  },
  /** Small units shared by Trophy Room rows and history. */
  units: {
    players_one: '{{count}} player',
    players_other: '{{count}} players',
    pts: '{{score}} pts',
    xpAwarded: '+{{xp}} XP',
    challenges_one: '{{count}} challenge',
    challenges_other: '{{count}} challenges',
  },
  /** Profile 2.0 header: the rank pill next to the name. */
  header: {
    level: 'Level {{level}}',
  },
  /** Compact XP card under the header (numbers come from the server rank). */
  xp: {
    total: 'Total XP',
    next: 'Next: {{rank}}',
    max: 'Max rank',
    toGo: '{{xp}} XP to {{rank}}',
    a11y: '{{xp}} XP, {{pct}} percent of the way to {{rank}}. Open all ranks',
    a11yMax: '{{xp}} XP, max rank reached. Open all ranks',
  },
  /** At-a-glance tiles under the XP card. */
  tiles: {
    bestDaily: 'Best daily',
  },
  /** Achievement / stats rows on Profile. */
  nav: {
    heading: 'Achievements & stats',
    stats: 'Statistics',
    statsSubtitle: 'Tournaments, guesses and scores.',
    daily: 'Daily Challenge',
    dailySubtitle: 'Your streak, games played and best score.',
    historySubtitle: 'Your finished tournaments.',
    settingsSubtitle: 'Sport, theme, language, notifications and account.',
  },
  /** "Your stats" screen segments. */
  statsScreen: {
    overall: 'Overall',
    daily: 'Daily',
    history: 'History',
  },
  /** Settings screen group headings. */
  settings: {
    preferences: 'Preferences',
    sport: 'Preferred sport',
    about: 'About',
  },
  /** CollapsibleSection (shared section header). */
  section: {
    a11y: '{{title}} section',
  },
};
