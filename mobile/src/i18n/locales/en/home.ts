/** Home screen (Play tab) + sport selection. */
export const home = {
  greeting: 'Hey, {{name}}',
  sportChip: {
    pick: '🎯 Pick a sport',
    change: 'Change sport ›',
  },
  daily: {
    title: '{{emoji}} Daily Ball Challenge',
    titleForSport: '{{emoji}} Daily {{sport}} Challenge',
    loading: 'Loading daily challenge…',
    noneToday: 'No challenge today. The next daily lands tomorrow.',
    noneForSport: 'No {{sport}} daily today — try Football, or the next one lands tomorrow.',
    dayOf: 'Day {{index}} of {{total}}',
    viewResult: "View Today's Result",
    play: 'Play Daily Challenge',
  },
  fallback: {
    message: 'No daily yet. Play a pack or join a tournament while you wait.',
    playPacks: 'Play packs',
    viewTournaments: 'View tournaments',
  },
  packs: {
    title: 'Challenge Packs',
    subtitle: 'Play themed sets of challenges.',
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
