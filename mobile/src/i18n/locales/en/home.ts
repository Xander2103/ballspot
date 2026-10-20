/** Home screen (Play tab) + sport selection. */
export const home = {
  greeting: 'Hey, {{name}}',
  /** Header state when /me failed for a recoverable reason (offline / 5xx). */
  profileUnavailable: "Couldn't load your profile.",
  sportChip: {
    pick: '🎯 Pick a sport',
    change: 'Change sport ›',
  },
  daily: {
    title: '{{emoji}} Daily Ball Challenge',
    titleForSport: '{{emoji}} Daily {{sport}} Challenge',
    loading: 'Loading daily challenge…',
    noneForSport: 'No {{sport}} daily today — try Football, or the next one lands tomorrow.',
    dayOf: 'Day {{index}} of {{total}}',
    viewResult: "View Today's Result",
    play: 'Play Daily Challenge',
    cardTitle: 'Daily Challenge',
    subtitle: 'A new challenge every day',
    completed: 'Completed today',
    nextTomorrow: 'Next challenge available tomorrow',
    nextLaterToday: 'Next challenge available later today',
    nextOn: 'Next challenge available on {{date}}',
    countdown: 'Next Daily in',
    countdownValue: '{{hours}}h {{minutes}}m',
    countdownA11y: 'Next Daily Challenge in {{hours}} hours and {{minutes}} minutes',
  },
  packs: {
    title: 'Challenge Packs',
    subtitle: 'Play themed sets of challenges.',
  },
  tournaments: {
    title: 'Tournaments',
    subtitle: 'Compete in tournaments.',
  },
  level: {
    a11y: 'Level {{level}}. Open your profile',
  },
  notifPrompt: {
    title: 'Stay in the game',
    message: 'Get a reminder when your Daily Challenge is ready or when a tournament needs your guess.',
    confirm: 'Enable notifications',
    cancel: 'Not now',
  },
  sportSelection: {
    title: 'Pick your sport',
    subtitle: 'Choose what you want to play first. You can change this later in your profile.',
    loadError: 'Could not load sports. Please try again.',
    saveError: 'Could not save your choice. Please try again.',
    comingSoonError: '{{sport}} is coming soon.',
    soonBadge: 'SOON',
    comingSoon: 'Coming soon',
    guessThe: 'Guess the {{object}}',
  },
};
