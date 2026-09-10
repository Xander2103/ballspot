<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Supported-language helpers shared by the middleware, notifications and the
 * reset-link builder. Mirrors config('ballspot.languages') and the mobile
 * app's src/utils/language.ts — keep the three in sync.
 */
final class Locale
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values((array) config('ballspot.languages', ['en']));
    }

    public static function default(): string
    {
        $default = (string) config('ballspot.default_language', 'en');

        return in_array($default, self::supported(), true) ? $default : 'en';
    }

    /** Normalise "nl-BE", "fr_FR", " DE " → a supported code, or null. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $primary = strtolower(trim(explode(',', $value)[0]));
        $primary = preg_split('/[-_;]/', $primary)[0] ?? '';

        return in_array($primary, self::supported(), true) ? $primary : null;
    }

    /**
     * First supported candidate wins; falls back to the configured default.
     *
     * @param iterable<mixed> $candidates
     */
    public static function resolve(iterable $candidates): string
    {
        foreach ($candidates as $candidate) {
            $code = self::normalize($candidate);
            if ($code !== null) {
                return $code;
            }
        }

        return self::default();
    }

    /** Best language for an HTTP request (see SetLocale for the order). */
    public static function forRequest(Request $request): string
    {
        return self::resolve([
            self::userLanguage($request),
            $request->input('preferred_language'),
            $request->query('lang'),
            $request->input('lang'),
            self::fromAcceptLanguage((string) $request->header('Accept-Language', '')),
        ]);
    }

    /**
     * The Accept-Language header lists weighted alternatives; take the first
     * one we support rather than only the first entry.
     */
    public static function fromAcceptLanguage(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $code = self::normalize(trim($part));
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    private static function userLanguage(Request $request): ?string
    {
        try {
            // Sanctum resolves a bearer token here even before the auth
            // middleware ran (public routes stay anonymous → null). The web
            // guard covers the admin/session side.
            $user = $request->user('sanctum') ?? $request->user('web');
        } catch (\Throwable) {
            return null;
        }

        return $user?->preferred_language;
    }
}
