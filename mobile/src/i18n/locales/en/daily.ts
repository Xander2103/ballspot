/** Daily challenge, daily result and the weekly/monthly leaderboard. */
export const daily = {
  streak_one: '🔥 {{count}} day streak',
  streak_other: '🔥 {{count}} day streak',
  challenge: {
    noChallengeTitle: 'No Challenge',
    noChallengeMessage: 'No daily challenge is available today.',
    loadError: 'Failed to load daily challenge.',
    monthProgress: 'Daily {{index}}/{{total}}',
  },
  result: {
    notFound: 'No result found.',
  },
  // Chosen from the API's period_type; neutral when the period is unknown.
  competition: {
    weekly: {
      periodName: 'Weekly',
      title: 'Weekly Leaderboard',
      viewLeaderboard: 'View Weekly Leaderboard',
      points: '{{score}} pts this week',
    },
    monthly: {
      periodName: 'Monthly',
      title: 'Monthly Leaderboard',
      viewLeaderboard: 'View Monthly Leaderboard',
      points: '{{score}} pts this month',
    },
    neutral: {
      title: 'Leaderboard',
      viewLeaderboard: 'View Leaderboard',
      points: '{{score}} pts',
    },
  },
  share: {
    cta: 'Share result',
    ctaHigh: 'Great score! Share your result',
    message: 'I scored {{score}} points in today’s BallPicker Daily Challenge ⚽🎯 Can you beat me?',
    xpAvailable: 'Share now and earn +{{xp}} XP',
    xpEarned: '+{{xp}} XP earned for sharing!',
    xpEarnedWithTrophy: '+{{xp}} XP earned: +{{share}} for sharing, +{{trophy}} for your new trophy!',
    rewardParts: {
      first_share: '+{{xp}} first share',
      daily_share: '+{{xp}} daily share',
      daily_high_score_share: '+{{xp}} high score',
    },
    thanks: 'Thanks for sharing!',
    error: 'Could not open the share sheet. Please try again.',
  },
  leaderboard: {
    loadError: 'Failed to load leaderboard.',
    playToEnter: 'Play a round to enter the leaderboard.',
    top: '🏆 Top',
    myRank: '📍 My rank',
    columns: {
      player: 'Player',
    },
    rowStats: '{{played}}x · avg {{avg}}',
    empty: 'No scores on the board yet.',
    emptyHint: "Play today's challenge to get on the board!",
    months: {
      m1: 'Jan',
      m2: 'Feb',
      m3: 'Mar',
      m4: 'Apr',
      m5: 'May',
      m6: 'Jun',
      m7: 'Jul',
      m8: 'Aug',
      m9: 'Sep',
      m10: 'Oct',
      m11: 'Nov',
      m12: 'Dec',
    },
  },
};
