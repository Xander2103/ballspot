import {
  getApiErrorMessage,
  isNetworkError,
  NETWORK_ERROR_MESSAGE,
  SERVER_ERROR_MESSAGE,
  UNAUTHORIZED_MESSAGE,
} from '../apiError';

describe('getApiErrorMessage', () => {
  const fallback = 'Failed to create tournament';

  it('returns the backend message for a clean 422 abort', () => {
    expect(
      getApiErrorMessage({ status: 422, message: 'You can only host one active tournament at a time.' }, fallback),
    ).toBe('You can only host one active tournament at a time.');
  });

  it('prefers the first validation error over the generic message', () => {
    expect(
      getApiErrorMessage(
        {
          status: 422,
          message: 'The given data was invalid.',
          errors: { duration_days: ['The selected duration is invalid.'], name: ['Required.'] },
        },
        fallback,
      ),
    ).toBe('The selected duration is invalid.');
  });

  it('hides "Server Error" and any 5xx behind a friendly sentence', () => {
    expect(getApiErrorMessage({ status: 500, message: 'Server Error' }, fallback)).toBe(SERVER_ERROR_MESSAGE);
    expect(getApiErrorMessage({ status: 503, message: 'Service Unavailable' }, fallback)).toBe(SERVER_ERROR_MESSAGE);
  });

  it('never surfaces exception dumps or stack traces', () => {
    const dump =
      'Illuminate\\Database\\QueryException: SQLSTATE[23000] in /var/www/app.php:12 Stack trace: #0 ...';
    expect(getApiErrorMessage({ status: 422, message: dump }, fallback)).toBe(fallback);
    expect(getApiErrorMessage({ status: 422, message: '<!DOCTYPE html><html>Whoops</html>' }, fallback)).toBe(fallback);
  });

  it('maps fetch network failures to a connection message', () => {
    expect(getApiErrorMessage(new TypeError('Network request failed'), fallback)).toBe(NETWORK_ERROR_MESSAGE);
    expect(getApiErrorMessage({ message: 'Failed to fetch' }, fallback)).toBe(NETWORK_ERROR_MESSAGE);
    expect(isNetworkError(new TypeError('x'))).toBe(true);
    expect(isNetworkError({ status: 422, message: 'nope' })).toBe(false);
  });

  it('maps 401 to a session-expired message', () => {
    expect(getApiErrorMessage({ status: 401, message: 'Unauthenticated.' }, fallback)).toBe(UNAUTHORIZED_MESSAGE);
  });

  it('keeps the existing 429 message from the client', () => {
    expect(
      getApiErrorMessage({ status: 429, retry_after: 30, message: 'Too many attempts. Try again in 30 seconds.' }, fallback),
    ).toBe('Too many attempts. Try again in 30 seconds.');
  });

  it('falls back for empty, generic or non-object errors', () => {
    expect(getApiErrorMessage(undefined, fallback)).toBe(fallback);
    expect(getApiErrorMessage(null, fallback)).toBe(fallback);
    expect(getApiErrorMessage({ status: 404, message: 'Request failed' }, fallback)).toBe(fallback);
    expect(getApiErrorMessage({ status: 422, message: '' }, fallback)).toBe(fallback);
    expect(getApiErrorMessage('Custom string', fallback)).toBe('Custom string');
  });
});
