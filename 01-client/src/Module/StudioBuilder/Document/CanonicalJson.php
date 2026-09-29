<?php
/**
 * Kohevo Studio (studio-builder) — Canonical JSON & Deterministic Fingerprinting.
 *
 * Ensures that any normalized Studio document serializes to an identical byte
 * sequence regardless of the order in which associative keys were constructed
 * in memory. Used for:
 * - Persisting `studiobuilder_revisions.document_json` and `studiobuilder_templates.document_json`
 * - Computing deterministic `SHA-256(canonical JSON)` content fingerprints
 * - Detecting no-op autosaves without relying on editor-generated JSON strings
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Exception\StudioValidationException;

final class CanonicalJson
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Decode a JSON string into an associative array after verifying size and depth limits.
     *
     * @return array<string, mixed>
     */
    public static function decode(
        string $rawJson,
        int $maxBytes = CanonicalDocumentSchema::MAX_DOCUMENT_BYTES,
        int $maxDepth = CanonicalDocumentSchema::MAX_JSON_DEPTH,
    ): array {
        $byteLength = strlen($rawJson);
        if ($byteLength === 0) {
            throw new StudioValidationException([
                ['path' => '$', 'code' => 'empty_json', 'message' => 'Document JSON payload must not be empty.'],
            ]);
        }
        if ($byteLength > $maxBytes) {
            throw new StudioValidationException([
                [
                    'path'    => '$',
                    'code'    => 'document_too_large',
                    'message' => "Document JSON exceeds maximum allowed size of {$maxBytes} bytes (got {$byteLength} bytes).",
                ],
            ]);
        }

        try {
            $decoded = json_decode($rawJson, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StudioValidationException([
                ['path' => '$', 'code' => 'invalid_json', 'message' => 'Malformed or overly deep JSON: ' . $e->getMessage()],
            ]);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new StudioValidationException([
                ['path' => '$', 'code' => 'invalid_root_type', 'message' => 'Canonical Studio document root must be a JSON object.'],
            ]);
        }

        return $decoded;
    }

    /**
     * Recursively sort associative array keys lexicographically while preserving list order.
     */
    public static function sortKeysRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::sortKeysRecursively($item);
            }
            return $out;
        }

        $sorted = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $sorted[(string) $k] = self::sortKeysRecursively($value[$k]);
        }

        return $sorted;
    }

    /**
     * Encode an associative array into canonical JSON with deterministic key ordering.
     *
     * Note: Empty associative maps intended as JSON objects (`props`, `style`, `bindings`,
     * `settings`, `seo`) are preserved as `{}` when converted via `toJsonObjectTree()`.
     *
     * @param array<string, mixed> $document
     */
    public static function encode(array $document): string
    {
        $sorted = self::sortKeysRecursively($document);
        $objectReady = self::preserveObjectMaps($sorted, '$');

        try {
            return json_encode($objectReady, self::ENCODE_FLAGS);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode canonical Studio JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Compute the deterministic 64-character lowercase hex SHA-256 fingerprint of a document.
     *
     * @param array<string, mixed> $document
     */
    public static function fingerprint(array $document): string
    {
        return hash('sha256', self::encode($document));
    }

    /**
     * Ensure associative map fields that may be empty (`[]` in PHP) serialize as `{}` in JSON.
     */
    private static function preserveObjectMaps(mixed $value, string $path): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // Known map paths that must always serialize as JSON objects `{}` even when empty in PHP
        $isKnownObjectMap = (bool) preg_match(
            '/^(\$\.settings|\$\.seo|\$\.sections\[\d+\]\.layout|\$\.sections\[\d+\]\.visibility|.*\.(props|style|visibility|bindings))$/',
            $path
        );

        if ($value === []) {
            return $isKnownObjectMap ? new \stdClass() : [];
        }

        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $idx => $item) {
                $out[] = self::preserveObjectMaps($item, "{$path}[{$idx}]");
            }
            return $out;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $childPath = $path === '$' ? '$.' . $k : $path . '.' . $k;
            $out[$k] = self::preserveObjectMaps($v, $childPath);
        }

        return $out;
    }
}
