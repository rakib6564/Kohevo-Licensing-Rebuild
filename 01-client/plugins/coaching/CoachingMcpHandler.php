<?php
/**
 * Coaching — MCP AI Gateway integration.
 *
 * Exposes the coach<->client chat and client list to the MCP gateway,
 * following plugins/booking/BookingMcpHandler.php's shape. Sending a
 * message goes out immediately as the practitioner, through the exact
 * same CoachingAPI::sendMessage() path the admin chat UI uses — there is
 * no separate/lesser-reviewed send path for the agent.
 */

declare(strict_types=1);

class CoachingMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['coaching.read']       = 'View enrolled clients and chat threads';
        $scopes['coaching.chat.send']  = 'Send a chat message to a client as the practitioner';
        $scopes['coaching.manage_library'] = 'Create or edit meal structures, recipes, and shopping lists (no delete)';
        return $scopes;
    }

    private static function has(array $context, string $scope): bool {
        return in_array($scope, (array)($context['scopes'] ?? []), true);
    }

    public static function filterTools(array $tools, array $context): array {
        if (self::has($context, 'coaching.read')) {
            $tools[] = [
                'name' => 'slate_coaching_list_clients',
                'description' => 'List currently enrolled coaching clients.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
            $tools[] = [
                'name' => 'slate_coaching_list_threads',
                'description' => 'List all chat threads with the last message preview and unread counts.',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ];
            $tools[] = [
                'name' => 'slate_coaching_get_messages',
                'description' => 'Read a client\'s chat thread (delivered messages, newest last).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'limit'       => ['type' => 'integer', 'description' => 'Default 200'],
                ], 'required' => ['customer_id'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'coaching.chat.send')) {
            $tools[] = [
                'name' => 'slate_coaching_send_message',
                'description' => 'Send a chat message to a client immediately, as the practitioner.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'body'        => ['type' => 'string'],
                ], 'required' => ['customer_id', 'body'], 'additionalProperties' => false],
            ];
        }
        if (self::has($context, 'coaching.manage_library')) {
            $tools[] = [
                'name' => 'slate_coaching_upsert_meal_structure',
                'description' => 'Create a new meal-structure template in the practitioner library, or update one when id is given. No delete.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'         => ['type' => 'integer', 'description' => 'Omit to create; provide to update.'],
                    'title'      => ['type' => 'string'],
                    'slot'       => ['type' => 'string', 'enum' => ['breakfast', 'lunch', 'dinner', 'snack', 'note'], 'description' => 'Default note.'],
                    'notes_html' => ['type' => 'string'],
                    'tags'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sort_order' => ['type' => 'integer'],
                ], 'required' => ['title'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_coaching_upsert_shopping_list',
                'description' => 'Create a new shopping-list template in the practitioner library, or update one when id is given. No delete.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'       => ['type' => 'integer', 'description' => 'Omit to create; provide to update.'],
                    'name'     => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'description' => 'e.g. [{"heading":"Produce","items":["Spinach","Carrots"]}]',
                        'items' => ['type' => 'object', 'properties' => [
                            'heading' => ['type' => 'string'],
                            'items'   => ['type' => 'array', 'items' => ['type' => 'string']],
                        ], 'required' => ['heading']],
                    ],
                    'tags'     => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['name'], 'additionalProperties' => false],
            ];
            $tools[] = [
                'name' => 'slate_coaching_upsert_recipe',
                'description' => 'Create a new recipe in the practitioner library, or update one when id is given. No delete.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id'                 => ['type' => 'integer', 'description' => 'Omit to create; provide to update.'],
                    'title'              => ['type' => 'string'],
                    'ingredients'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'One ingredient per line.'],
                    'instructions_html'  => ['type' => 'string'],
                    'video_url'          => ['type' => 'string'],
                    'notes'              => ['type' => 'string'],
                    'tags'               => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['title'], 'additionalProperties' => false],
            ];
        }
        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        if ($name === 'slate_coaching_list_clients') {
            self::requireScope($context, 'coaching.read');
            return ['clients' => CoachingAPI::listEnrolledClients()];
        }

        if ($name === 'slate_coaching_list_threads') {
            self::requireScope($context, 'coaching.read');
            return ['threads' => CoachingAPI::listThreads()];
        }

        if ($name === 'slate_coaching_get_messages') {
            self::requireScope($context, 'coaching.read');
            $cid = (int)($args['customer_id'] ?? 0);
            if ($cid <= 0) throw new InvalidArgumentException('customer_id is required.');
            $thread = CoachingAPI::getThread($cid);
            if (!$thread) return ['thread' => null, 'messages' => []];
            $limit = max(1, min(1000, (int)($args['limit'] ?? 200)));
            return ['thread' => $thread, 'messages' => CoachingAPI::listMessages((int)$thread['id'], false, $limit)];
        }

        if ($name === 'slate_coaching_send_message') {
            self::requireScope($context, 'coaching.chat.send');
            $cid  = (int)($args['customer_id'] ?? 0);
            $body = trim((string)($args['body'] ?? ''));
            if ($cid <= 0) throw new InvalidArgumentException('customer_id is required.');
            if ($body === '') throw new InvalidArgumentException('body is required.');
            $customer = Database::row('SELECT id FROM customers WHERE id = ? AND tenant_id = ?', [$cid, current_tenant_id()]);
            if (!$customer) throw new InvalidArgumentException('Customer not found.');
            $threadId = CoachingAPI::ensureThread($cid);
            $msgId = CoachingAPI::sendMessage($threadId, 'practitioner', $body);
            if ($msgId <= 0) throw new RuntimeException('Could not send the message.');
            return ['ok' => true, 'thread_id' => $threadId, 'message_id' => $msgId];
        }

        if ($name === 'slate_coaching_upsert_meal_structure') {
            self::requireScope($context, 'coaching.manage_library');
            $title = trim((string)($args['title'] ?? ''));
            if ($title === '') throw new InvalidArgumentException('title is required.');
            $id = CoachingAPI::saveMealStructure([
                'id'         => (int)($args['id'] ?? 0),
                'title'      => $title,
                'slot'       => (string)($args['slot'] ?? 'note'),
                'notes_html' => (string)($args['notes_html'] ?? ''),
                'tags'       => (array)($args['tags'] ?? []),
                'sort_order' => (int)($args['sort_order'] ?? 0),
                'customer_id' => null,
            ]);
            if ($id <= 0) throw new RuntimeException('Could not save the meal structure.');
            return ['ok' => true, 'meal_structure_id' => $id];
        }

        if ($name === 'slate_coaching_upsert_shopping_list') {
            self::requireScope($context, 'coaching.manage_library');
            $listName = trim((string)($args['name'] ?? ''));
            if ($listName === '') throw new InvalidArgumentException('name is required.');
            $id = CoachingAPI::saveShoppingList([
                'id'          => (int)($args['id'] ?? 0),
                'name'        => $listName,
                'sections'    => (array)($args['sections'] ?? []),
                'tags'        => (array)($args['tags'] ?? []),
                'customer_id' => null,
            ]);
            if ($id <= 0) throw new RuntimeException('Could not save the shopping list.');
            return ['ok' => true, 'shopping_list_id' => $id];
        }

        if ($name === 'slate_coaching_upsert_recipe') {
            self::requireScope($context, 'coaching.manage_library');
            $title = trim((string)($args['title'] ?? ''));
            if ($title === '') throw new InvalidArgumentException('title is required.');
            $recipeId = (int)($args['id'] ?? 0);
            // saveRecipe() always writes photo_path as given (empty = clear) —
            // there's no upload path through chat, so preserve whatever photo
            // an existing recipe already has instead of wiping it on every edit.
            $existingPhoto = null;
            if ($recipeId > 0) {
                $existingPhoto = Database::value(
                    'SELECT photo_path FROM coaching_recipe WHERE id = ? AND tenant_id = ?',
                    [$recipeId, current_tenant_id()]
                );
            }
            $id = CoachingAPI::saveRecipe([
                'id'                => $recipeId,
                'author'            => 'practitioner',
                'title'             => $title,
                'photo_path'        => $existingPhoto,
                'ingredients'       => (array)($args['ingredients'] ?? []),
                'instructions_html' => (string)($args['instructions_html'] ?? ''),
                'video_url'         => (string)($args['video_url'] ?? ''),
                'notes'             => (string)($args['notes'] ?? ''),
                'tags'              => (array)($args['tags'] ?? []),
                'customer_id'       => null,
            ]);
            if ($id <= 0) throw new RuntimeException('Could not save the recipe.');
            return ['ok' => true, 'recipe_id' => $id];
        }

        return null;
    }

    private static function requireScope(array $context, string $scope): void {
        if (!in_array($scope, (array)($context['scopes'] ?? []), true)) {
            throw new RuntimeException("This token does not grant the \"$scope\" scope.");
        }
    }
}
