<?php

namespace App\Support;

/**
 * Which store a visitor of /download belongs to.
 *
 * Link-preview bots and search crawlers come first: several of them claim to be
 * a phone (Googlebot Smartphone says "Android"), and a redirect would send them
 * to the store listing instead of our Open Graph tags. The list is explicit
 * because a bare "bot" also matches real phones such as the Cubot range.
 */
final class StorePlatform
{
    private const CRAWLERS = '/whatsapp|facebookexternalhit|facebot|meta-externalagent|twitterbot|telegrambot'
        .'|linkedinbot|slackbot|discordbot|skypeuripreview|pinterestbot|redditbot|embedly|viber'
        .'|googlebot|adsbot-google|mediapartners-google|google-inspectiontool|bingbot|applebot'
        .'|duckduckbot|yandexbot|baiduspider|petalbot|slurp/i';

    /** @return 'ios'|'android'|null null = show the page with both store buttons */
    public static function fromUserAgent(?string $userAgent): ?string
    {
        $userAgent = (string) $userAgent;

        // Windows Phone user agents claim both "Android" and "iPhone".
        if (preg_match(self::CRAWLERS, $userAgent) || stripos($userAgent, 'Windows Phone') !== false) {
            return null;
        }
        if (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            return 'ios';
        }

        return stripos($userAgent, 'Android') !== false ? 'android' : null;
    }
}
