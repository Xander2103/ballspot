/**
 * Error copy. `auth.*` keys are the stable backend codes from
 * backend/app/Support/AuthError.php — the app translates the CODE, never the
 * server sentence. Add a code there, add it here (in every language).
 */
export const errors = {
  network: 'Could not reach BallPicker. Check your connection and try again.',
  server: 'Something went wrong on our side. Please try again in a moment.',
  unauthorized: 'Your session has expired. Please log in again.',
  generic: 'Something went wrong. Please try again.',
  rateLimited: 'Too many attempts. Try again in {{seconds}} seconds.',
  validation: {
    required: 'This field is required.',
    emailRequired: 'Email is required.',
    emailInvalid: 'Enter a valid email address.',
    passwordRequired: 'Password is required.',
    passwordMin: 'Password must be at least 8 characters.',
    passwordConfirmRequired: 'Please confirm your password.',
    usernameRequired: 'Username is required.',
    nameRequired: 'Name is required.',
    codeRequired: 'Enter the 6-digit code from your email.',
  },
  verification: {
    sessionExpired: 'Your session has expired. Please log in again to continue verifying.',
    sessionMismatch: 'This device is signed in to a different account than the one you are verifying. Please log in again with the account you just created.',
    invalidOrExpired: 'Invalid or expired verification code.',
    noToken: 'Registration did not return a session token.',
    storeFailed: 'Could not store the session on this device.',
    resentTo: 'A new code has been sent to {{email}}. Codes from earlier emails still work too.',
    resent: 'A new code has been sent to your email. Codes from earlier emails still work too.',
  },
  auth: {
    email_taken: 'An account with this email already exists. Please log in or reset your password.',
    username_taken: 'This username is already taken.',
    password_mismatch: 'Passwords do not match.',
    validation_failed: 'Please check the highlighted fields.',
    invalid_credentials: 'Invalid email or password.',
    account_deleted: 'This account has been deleted. You can create a new account with the same email.',
    two_factor_required: 'We sent a verification code to your email.',
    two_factor_code_invalid: 'That code is not correct. Check the newest email and try again.',
    two_factor_code_expired: 'This code has expired. Please log in again to get a new one.',
    two_factor_locked: 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
    two_factor_session_invalid: 'This login session has expired. Please log in again.',
    verification_code_invalid: 'That code is not correct. Check the newest email and try again.',
    verification_code_expired: 'This code has expired. Tap "Resend code" to get a new one.',
    verification_locked: 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
    verification_no_code: 'No code is active for this account. Tap "Resend code" to get a new one.',
    reset_token_invalid: 'This reset link is invalid. Request a new one and use the newest email.',
    reset_token_expired: 'This reset link has expired. Request a new one and use the newest email.',
    reset_failed: 'We could not reset your password right now. Please try again in a moment.',
    session_mismatch: 'This code belongs to a different account than the one signed in on this device. Please log in again with the account you just created.',
    admin_account_protected: 'Admin accounts cannot be deleted from the app.',
  },
};
