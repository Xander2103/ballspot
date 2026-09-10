<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pick the language every translated string on this request renders in.
 *
 * Order (first supported value wins — see App\Support\Locale::resolve):
 *   1. the signed-in user's preferred_language (API token or web session)
 *   2. an explicit `preferred_language` input (register: the language the
 *      user just chose must already apply to the validation errors)
 *   3. `?lang=` / a `lang` form field (the link in the reset email carries
 *      the recipient's language onto the web fallback page)
 *   4. the Accept-Language header (the app sends its active language)
 *   5. BALLPICKER_DEFAULT_LANGUAGE, then English
 *
 * Unsupported values are ignored — never an exception, never a 4xx. Emails
 * are NOT affected by this: notifications render under the recipient's own
 * preferred_language via HasLocalePreference, whoever triggered them.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locale::forRequest($request));

        return $next($request);
    }
}
