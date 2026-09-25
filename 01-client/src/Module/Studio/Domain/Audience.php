<?php
/**
 * Studio — Audience (pure).
 *
 * Turns candidate rows into the list a broadcast will actually be sent to.
 * The database decides WHO is in a group (everyone in Beginner Ballet, everyone
 * with a balance); this decides what happens to that list before it becomes
 * email, which is where the mistakes live:
 *
 *  - A parent with three dancers appears three times in a class-join query.
 *    Sending them the same snow-day notice three times is the single most
 *    obvious way to look amateurish, so the list is deduplicated by email.
 *  - Addresses are compared case-insensitively and trimmed. "Alex@Example.com "
 *    and "alex@example.com" are one parent, and treating them as two produces
 *    the duplicate this class exists to prevent.
 *  - Anything without a usable address is dropped rather than counted. A
 *    recipient count that includes people who cannot receive is a lie the
 *    studio only discovers after pressing send.
 *
 * The first occurrence of an address wins, so the name shown is the one from
 * the earliest row — stable ordering means a preview and the real send agree.
 *
 * Pure and DB-free: StudioAPI runs the queries and hands the rows here.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class Audience
{
    /** Audience kinds a broadcast can target. */
    public const KINDS = ['all', 'class', 'unpaid', 'age'];

    /**
     * Clean a candidate list into deliverable recipients.
     *
     * @param  array<int,array> $rows  each with email, and optionally name/contact_id
     * @return array<int,array{email:string,name:string,contact_id:?int}>
     */
    public static function recipients(array $rows): array
    {
        $seen = [];
        $out  = [];

        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }

            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if (!self::deliverable($email)) { continue; }
            if (isset($seen[$email])) { continue; }
            $seen[$email] = true;

            $out[] = [
                'email'      => $email,
                'name'       => trim((string) ($r['name'] ?? '')),
                'contact_id' => isset($r['contact_id']) && $r['contact_id'] !== null
                              ? (int) $r['contact_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Is this address worth attempting?
     *
     * Deliberately permissive — this is a pre-send filter, not validation.
     * Rejecting a slightly unusual but legitimate address would silently drop
     * a family from every announcement, which is worse than one bounce.
     */
    public static function deliverable(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) { return false; }
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return $local !== '' && $domain !== '' && str_contains($domain, '.');
    }

    /** A kind we recognise, or 'all'. */
    public static function normaliseKind(?string $kind): string
    {
        $kind = strtolower(trim((string) $kind));
        return in_array($kind, self::KINDS, true) ? $kind : 'all';
    }

    /**
     * How many candidate rows were discarded, for the preview.
     *
     * Shown rather than hidden: "41 families (3 have no email address)" tells a
     * studio owner to go and fix three records. A bare "41" does not.
     *
     * @return array{total:int,deliverable:int,duplicates:int,unreachable:int}
     */
    public static function summarise(array $rows): array
    {
        $total       = 0;
        $unreachable = 0;
        $seen        = [];
        $dupes       = 0;

        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $total++;
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if (!self::deliverable($email)) { $unreachable++; continue; }
            if (isset($seen[$email])) { $dupes++; continue; }
            $seen[$email] = true;
        }

        return [
            'total'       => $total,
            'deliverable' => count($seen),
            'duplicates'  => $dupes,
            'unreachable' => $unreachable,
        ];
    }

    /**
     * Substitute the handful of tokens a studio actually wants in a broadcast.
     * Unknown tokens are left alone rather than blanked — a literal {balance}
     * in the sent copy is a visible bug the studio can fix, whereas silently
     * emptying it looks like the parent owes nothing.
     *
     * @param array<string,string> $vars
     */
    public static function personalise(string $body, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $body = str_replace('{' . $k . '}', (string) $v, $body);
        }
        return $body;
    }
}
