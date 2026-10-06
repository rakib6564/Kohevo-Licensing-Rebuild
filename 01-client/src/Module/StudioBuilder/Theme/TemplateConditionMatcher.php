<?php
/**
 * Kohevo Studio (studio-builder) — Template Condition Matcher.
 *
 * Evaluates declarative conditions on Theme Builder templates against a given
 * request or entity context. Calculates deterministic specificity scores so
 * more specific templates take precedence over general or site-wide templates:
 *
 *   Specific condition (category, author, tag, id, slug) -> Score 300
 *   Content-type condition (singular, post, archive)     -> Score 200
 *   Global / entire site condition                      -> Score 100
 *   No match                                            -> Score 0
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Theme;

final class TemplateConditionMatcher
{
    public const SCORE_SPECIFIC     = 300;
    public const SCORE_CONTENT_TYPE = 200;
    public const SCORE_GLOBAL       = 100;
    public const SCORE_NONE         = 0;

    /**
     * Check if the condition matches the given context.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $conditions
     * @param array<string, mixed>                            $context
     */
    public static function matches(mixed $conditions, array $context): bool
    {
        return self::score($conditions, $context) > self::SCORE_NONE;
    }

    /**
     * Calculate specificity score for a set of conditions.
     *
     * @param mixed                $conditions Array or list of condition rules.
     * @param array<string, mixed> $context    Evaluation context (e.g. category, page_type, author_id).
     * @return int Highest matching score, or 0 if no rule matches or if an exclude rule matches.
     */
    public static function score(mixed $conditions, array $context): int
    {
        if (empty($conditions) || !is_array($conditions)) {
            return self::SCORE_NONE;
        }

        $rules = self::normalizeRules($conditions);
        if (empty($rules)) {
            return self::SCORE_NONE;
        }

        $highest = self::SCORE_NONE;
        $excluded = false;

        foreach ($rules as $rule) {
            $action = strtolower(trim((string) ($rule['action'] ?? $rule['type'] ?? 'include')));
            $isExclude = ($action === 'exclude');

            $ruleScore = self::evaluateRule($rule, $context);
            if ($ruleScore > self::SCORE_NONE) {
                if ($isExclude) {
                    $excluded = true;
                } elseif ($ruleScore > $highest) {
                    $highest = $ruleScore;
                }
            }
        }

        if ($excluded) {
            return self::SCORE_NONE;
        }

        return $highest;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private static function evaluateRule(array $rule, array $context): int
    {
        $scope = strtolower(trim((string) ($rule['condition'] ?? $rule['scope'] ?? '')));
        if ($scope === '') {
            $type = strtolower(trim((string) ($rule['type'] ?? '')));
            if (!in_array($type, ['include', 'exclude'], true)) {
                $scope = $type;
            }
        }

        if ($scope === '' || $scope === 'entire_site' || $scope === 'all' || $scope === 'global') {
            return self::SCORE_GLOBAL;
        }

        // Front page rule
        if ($scope === 'front_page' || $scope === 'home') {
            $slug = strtolower(trim((string) ($context['slug'] ?? '')));
            $isFront = !empty($context['is_front_page']) || $slug === '' || $slug === 'home' || $slug === 'index';
            return $isFront ? self::SCORE_SPECIFIC : self::SCORE_NONE;
        }

        // Category rule
        if ($scope === 'category') {
            $expected = (string) ($rule['value'] ?? $rule['category'] ?? '');
            $actual   = (string) ($context['category'] ?? '');
            if ($expected !== '' && strcasecmp($expected, $actual) === 0) {
                return self::SCORE_SPECIFIC;
            }
            return self::SCORE_NONE;
        }

        // Tag rule
        if ($scope === 'tag') {
            $expected = strtolower(trim((string) ($rule['value'] ?? $rule['tag'] ?? '')));
            $actualTags = (array) ($context['tags'] ?? []);
            foreach ($actualTags as $tag) {
                if (strtolower(trim((string) $tag)) === $expected) {
                    return self::SCORE_SPECIFIC;
                }
            }
            return self::SCORE_NONE;
        }

        // Author rule
        if ($scope === 'author') {
            $expected = (int) ($rule['value'] ?? $rule['author_id'] ?? 0);
            $actual   = (int) ($context['author_id'] ?? $context['created_by'] ?? 0);
            if ($expected > 0 && $expected === $actual) {
                return self::SCORE_SPECIFIC;
            }
            return self::SCORE_NONE;
        }

        // Specific page/post ID or slug
        if ($scope === 'singular_id' || $scope === 'id') {
            $expected = (int) ($rule['value'] ?? $rule['id'] ?? 0);
            $actual   = (int) ($context['id'] ?? $context['page_id'] ?? 0);
            return ($expected > 0 && $expected === $actual) ? self::SCORE_SPECIFIC : self::SCORE_NONE;
        }

        if ($scope === 'slug') {
            $expected = (string) ($rule['value'] ?? $rule['slug'] ?? '');
            $actual   = (string) ($context['slug'] ?? '');
            return ($expected !== '' && strcasecmp($expected, $actual) === 0) ? self::SCORE_SPECIFIC : self::SCORE_NONE;
        }

        // Singular / Content-Type rule
        if ($scope === 'singular' || $scope === 'post_type' || $scope === 'page_type') {
            $expected = strtolower(trim((string) ($rule['value'] ?? $rule['post_type'] ?? 'all')));
            $actual   = strtolower(trim((string) ($context['content_type'] ?? $context['page_type'] ?? 'page')));

            // If a nested field condition is specified, e.g. singular with category
            if (!empty($rule['field']) && $rule['field'] === 'category') {
                $expVal = (string) ($rule['field_value'] ?? $rule['value'] ?? '');
                $actVal = (string) ($context['category'] ?? '');
                if ($expVal !== '' && strcasecmp($expVal, $actVal) === 0) {
                    return self::SCORE_SPECIFIC;
                }
                return self::SCORE_NONE;
            }

            if ($expected === 'all' || $expected === $actual) {
                return self::SCORE_CONTENT_TYPE;
            }

            $slug = strtolower(trim((string) ($context['slug'] ?? '')));
            if ($expected !== '' && $expected === $slug) {
                return self::SCORE_SPECIFIC;
            }

            return self::SCORE_NONE;
        }

        // Archive rule
        if ($scope === 'archive') {
            $expectedType = strtolower(trim((string) ($rule['archive_type'] ?? $rule['value'] ?? 'all')));
            $actualType   = strtolower(trim((string) ($context['archive_type'] ?? 'all')));

            if (!empty($rule['term'])) {
                $expTerm = (string) $rule['term'];
                $actTerm = (string) ($context['term'] ?? '');
                if (strcasecmp($expTerm, $actTerm) === 0) {
                    return self::SCORE_SPECIFIC;
                }
                return self::SCORE_NONE;
            }

            if ($expectedType === 'all' || $expectedType === $actualType) {
                return self::SCORE_CONTENT_TYPE;
            }
            return self::SCORE_NONE;
        }

        // 404 rule
        if ($scope === '404' || $scope === 'not_found') {
            return !empty($context['is_404']) ? self::SCORE_SPECIFIC : self::SCORE_NONE;
        }

        // Search rule
        if ($scope === 'search') {
            return !empty($context['is_search']) ? self::SCORE_SPECIFIC : self::SCORE_NONE;
        }

        return self::SCORE_NONE;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $conditions
     * @return list<array<string, mixed>>
     */
    private static function normalizeRules(array $conditions): array
    {
        if (isset($conditions['rules']) && is_array($conditions['rules'])) {
            return self::normalizeRules($conditions['rules']);
        }

        if (array_is_list($conditions)) {
            $out = [];
            foreach ($conditions as $c) {
                if (is_array($c)) {
                    $out[] = $c;
                }
            }
            return $out;
        }

        return [$conditions];
    }
}
