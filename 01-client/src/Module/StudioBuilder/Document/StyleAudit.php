<?php
/**
 * Kohevo Studio (studio-builder) — which styling in a stored document the current rules refuse.
 *
 * A block that fails validation is rendered as "unavailable" (nothing, on a public page), so this
 * answers the question that matters before a deploy: which blocks would disappear? It runs the real
 * validator and keeps the errors that are about styling, so it can never disagree with what the
 * renderer will do. Read-only.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class StyleAudit
{
    /**
     * @param array<string, mixed> $document decoded canonical document
     * @return list<array{path: string, code: string, message: string}> styling errors only
     */
    public static function issues(array $document, BlockRegistry $registry): array
    {
        $result = DocumentValidator::validate($document, $registry);
        if ($result->isValid()) {
            return [];
        }
        $out = [];
        foreach ($result->errors() as $error) {
            $path = (string) ($error['path'] ?? '');
            if (preg_match('/\.(style|style_states|tag)(\.|\[|$)/', $path) === 1) {
                $out[] = ['path' => $path, 'code' => (string) ($error['code'] ?? ''), 'message' => (string) ($error['message'] ?? '')];
            }
        }
        return $out;
    }
}
