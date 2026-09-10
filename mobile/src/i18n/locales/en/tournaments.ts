/** Tournaments tab: list, create, join, detail (lobby/active/completed) and rivalry copy. */
export const tournaments = {
  /** Not enough tournament content right now (backend code TOURNAMENTS_TEMPORARILY_UNAVAILABLE). */
  unavailable: {
    title: 'Tournaments temporarily unavailable',
    body: 'We’re preparing new challenges. Please try again soon.',
    hint: 'You can still open and play your existing tournaments.',
  },
  /** Status badge labels rendered from the league status code. */
  status: {
    lobby: 'LOBBY',
    active: 'ACTIVE',
    completed: 'DONE',
  },
  /** Shared bits reused by the list card and the detail header. */
  code: 'Code: {{code}}',
  players_one: '{{count}} player',
  players_other: '{{count}} players',
  alerts: {
    error: 'Error',
  },

  list: {
    create: '+ Create',
    join: 'Join',
    sections: {
      yours: 'Your Tournaments',
      completed: 'Completed',
    },
    empty: {
      yours: 'No lobby or active tournaments right now.',
      completed: 'No completed tournaments yet.',
      title: 'No tournaments yet',
      message: 'Create one or join with a code.',
    },
    loadFailed: {
      title: "Couldn't load your tournaments",
    },
    card: {
      rounds: '{{completed}}/{{total}} rounds',
      removeFromList: 'Remove {{name}} from your list',
      delete: 'Delete',
    },
    deleteModal: {
      lobbyTitle: 'Delete lobby?',
      tournamentTitle: 'Delete tournament?',
      lobbyMessage: 'This lobby has not started yet. Are you sure you want to delete it?',
      tournamentMessage: 'This will remove the tournament from your active list. Players will no longer be able to continue it.',
      lobbyConfirm: 'Delete lobby',
      tournamentConfirm: 'Delete tournament',
      keepLobby: 'Keep lobby',
      error: 'Could not delete the tournament. Please try again.',
    },
    hideModal: {
      title: 'Remove tournament?',
      message: 'This will remove it from your list. Your result/history will stay saved.',
      confirm: 'Remove',
      error: 'Could not remove the tournament. Please try again.',
    },
  },

  create: {
    title: 'New Tournament',
    sport: 'Sport',
    defaultSport: '⚽ Football',
    nameLabel: 'Tournament Name',
    namePlaceholder: 'e.g. Friday Squad',
    duration: 'Duration',
    oneMonth: '1 month',
    photos_one: '{{count}} photo',
    photos_other: '{{count}} photos',
    /** Accessibility label for a duration option: "7 days, 7 photos". */
    durationOption: '{{label}}, {{photos}}',
    helper: 'Players get 1 photo per day.',
    summary: '{{label}} · {{photos}}',
    submit: 'Create Tournament',
    limitsNote: 'You can host 1 tournament and be in up to 2 at the same time. Up to 8 players each.',
    changeSportNote: 'You can change your sport in your profile.',
    errors: {
      nameRequired: 'Tournament name is required',
      title: 'Could not create tournament',
      failed: 'Failed to create tournament. Please try again.',
    },
  },

  join: {
    title: 'Join a League',
    subtitle: 'Ask the league creator for their 6-character join code.',
    codeLabel: 'Join Code',
    codePlaceholder: 'ABC123',
    submit: 'Join League',
    errors: {
      codeLength: 'Join code must be 6 characters',
      title: 'Could not join tournament',
      notFound: 'No tournament found for that code. Check the code and try again.',
      failed: 'Could not join this tournament. Please try again.',
    },
  },

  detail: {
    errors: {
      invalidLeague: 'Invalid league — no ID was passed to this screen.',
      loadFailed: 'Failed to load tournament',
      startTitle: 'Could not start tournament',
      startFailed: 'Failed to start tournament. Please try again.',
    },
    removed: {
      title: 'You were removed',
      message: 'You have been removed from this tournament.',
      backHome: 'Back to Home',
    },
    lobby: {
      title: 'Waiting in Lobby',
      summary_one: '{{players}} joined · {{count}} round total',
      summary_other: '{{players}} joined · {{count}} rounds total',
      playersTitle: 'Players in Lobby ({{count}})',
      start: 'Start Tournament',
      waitingForOwner: 'Waiting for the owner to start…',
    },
    active: {
      playedAllToday: "✓ You've played all rounds for today",
      comeBackTomorrow: 'Come back tomorrow for more rounds.',
      playRound: '▶ Play Current Round',
      allDoneForNow: '✓ All rounds completed for now',
      todayProgress: 'Today: {{played}}/{{total}} rounds played',
      progress: '{{completed}}/{{total}} rounds completed ({{pct}}%)',
    },
    fullLeaderboard: 'Full Leaderboard',
    completed: {
      title: 'Tournament Finished',
      message: 'All rounds played. Check the final standings below.',
    },
    cancelled: 'This tournament was cancelled.',
    leaderboardPreview: 'Leaderboard Preview',
    startModal: {
      title: 'Start Tournament?',
      message: 'This will generate {{rounds}} rounds and open play for all members. You cannot undo this.',
      confirm: 'Start Now',
      cancel: 'Not Yet',
    },
    removeModal: {
      title: 'Remove player?',
      message: 'This player will be removed from the lobby.',
      confirm: 'Remove',
    },
  },

  /** Rivalry status line + days-left label (src/utils/rivalry.ts). */
  rivalry: {
    points_one: '{{count}} point',
    points_other: '{{count}} points',
    aPlayer: 'A player',
    tied: "It's currently tied.",
    leading: 'You are leading by {{points}}',
    behind: 'You are {{points}} behind {{name}}',
    leaderLeads: '{{name}} leads by {{points}}',
    daysLeft_one: '{{count}} day left',
    daysLeft_other: '{{count}} days left',
  },
};
