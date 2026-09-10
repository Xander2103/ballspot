/** Login, registration, email/2FA verification and password-reset screens. */
export const auth = {
  backToLogin: 'Back to login',
  login: {
    tagline: 'Find the ball. Beat your friends.',
    missingFields: 'Please enter your email and password.',
    failed: 'Login failed. Please check your details and try again.',
    submit: 'Login',
    forgot: 'Forgot password?',
  },
  register: {
    title: 'Create Account',
    fields: {
      fullName: 'Full Name',
      password: 'Password (at least 8 characters)',
      confirmPassword: 'Confirm password',
      betaCode: 'Beta code',
    },
    validation: {
      fullNameRequired: 'Full name is required.',
      betaCodeRequired: 'A beta code is required during closed testing.',
    },
    consentRequired: 'Please confirm your age and agree to the Terms and Privacy Policy to create an account.',
    failed: 'Registration failed. Please try again.',
    consent: {
      ageAndTerms: 'I am at least {{age}} years old, I agree to the',
      andRead: 'and have read the',
    },
    haveAccount: 'Already have an account? Log in',
  },
  /** Shared by the email-verification and login-2FA code screens. */
  verification: {
    checkEmail: 'Check your email',
    resend: 'Resend code',
    resendIn: 'Resend code in {{seconds}}s',
    resendFailed: 'Could not resend the code. Please try again in a moment.',
  },
  emailVerification: {
    subtitle: 'We sent a 6-digit verification code. Enter it to activate your account.',
    subtitleTo: 'We sent a 6-digit verification code to {{email}}. Enter it to activate your account.',
    notSent: 'We could not send a new email just now. Enter the code you already received, or tap "Resend code".',
    loginAgain: 'Log in again',
    verify: 'Verify email',
    hint: "Didn't get it? Check your spam folder. Codes stay valid for an hour, and older codes keep working after a resend.",
  },
  loginVerification: {
    subtitle: 'Enter the 6-digit code we sent you.',
    subtitleTo: 'Enter the 6-digit code we sent to {{email}}.',
    resent: 'A new code has been sent to your email.',
    verify: 'Verify and continue',
  },
  forgotPassword: {
    title: 'Forgot password?',
    intro: "Enter the email for your account and we'll send you a link to reset your password.",
    emailRequired: 'Please enter your email address.',
    failed: 'We could not send the reset email right now. Please try again.',
    submit: 'Send reset link',
    sentPrefix: 'If an account exists for',
    sentSuffix: "we've sent a link to reset your password.",
    sentHint: 'Open the link on any device to choose a new password, or copy the link and paste it on the next screen. The link expires after a while — if it stops working, request a new one here.',
    haveLink: 'I have the link',
  },
  resetPassword: {
    title: 'Reset password',
    intro: 'Paste the reset link from your email below (the code inside it works too), then choose a new password.',
    fields: {
      link: 'Reset link or code',
      newPassword: 'New password (at least 8 characters)',
      confirmNewPassword: 'Confirm new password',
    },
    validation: {
      linkRequired: 'Paste the reset link (or the code from it) from your email.',
    },
    invalidLink: 'This reset link is invalid or has expired. Request a new one and use the newest email.',
    submit: 'Set new password',
    requestNewLink: 'Request a new link',
    goToLogin: 'Go to login',
    done: {
      title: 'Password updated',
      body: 'Your password has been changed and every other session has been signed out. Log in with your new password.',
    },
    expired: {
      titleExpired: 'This link has expired',
      titleInvalid: 'This link no longer works',
      bodyExpired: 'Reset links are valid for a limited time. Request a new link and use the newest email.',
      bodyInvalid: 'Reset links can only be used once and must match the email they were sent to. Request a new link and use the newest email.',
    },
  },
};
