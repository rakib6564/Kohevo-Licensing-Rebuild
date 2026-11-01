<?php
/**
 * Kohevo Studio — which Search Console and Analytics properties belong to this site, so signing in needs no second step
 * when the answer is clear. No match leaves the choice to the person on the Settings tab.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class GoogleChoices
{
    private const STREAM_LOOKUPS = 10;

    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * The domain property first, then an address on this host, then the only one there is.
     *
     * @param list<array{url: string, level: string}> $sites
     */
    public static function matchSite(array $sites, string $host): string
    {
        $urls = array_column($sites, 'url');
        $bare = self::bare($host);
        foreach (['sc-domain:' . $bare, 'https://' . $bare . '/', 'https://www.' . $bare . '/', 'http://' . $bare . '/', 'http://www.' . $bare . '/'] as $candidate) {
            if (in_array($candidate, $urls, true)) {
                return $candidate;
            }
        }
        return count($urls) === 1 ? $urls[0] : '';
    }

    /**
     * A property with the host in its name, then one whose web stream is on the host, then the only one there is.
     *
     * @param list<array{property: string, name: string, account: string}> $properties
     * @param \Closure(string): list<string>                               $streamUris a property's web addresses
     */
    public static function matchProperty(array $properties, string $host, \Closure $streamUris): string
    {
        $bare = self::bare($host);
        foreach ($properties as $property) {
            if ($bare !== '' && str_contains(strtolower($property['name']), $bare)) {
                return $property['property'];
            }
        }
        foreach (array_slice($properties, 0, self::STREAM_LOOKUPS) as $property) {
            foreach ($streamUris($property['property']) as $uri) {
                if (self::bare((string) parse_url($uri, PHP_URL_HOST)) === $bare) {
                    return $property['property'];
                }
            }
        }
        return count($properties) === 1 ? $properties[0]['property'] : '';
    }
}
