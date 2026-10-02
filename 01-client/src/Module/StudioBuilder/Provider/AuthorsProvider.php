<?php
/**
 * Kohevo Studio (studio-builder) — `content.authors` Data Provider.
 *
 * Provides author metadata for blog and article archives.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class AuthorsProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'content.authors';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'limit', 'type' => 'number', 'label' => 'Limit', 'required' => false, 'default' => 10, 'min' => 1, 'max' => 50, 'integer_only' => true],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return null;
    }

    public function requiredPermission(): string
    {
        return StudioPermissions::VIEW;
    }

    public function maxResults(): int
    {
        return 50;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        $tenantId = $tenants->id();
        if ($tenantId <= 0) {
            return [];
        }
        $limit = max(1, min(50, (int) ($params['limit'] ?? 10)));

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT ?');
        $stmt->bindValue(1, $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'    => (int) $row['id'],
                'name'  => (string) $row['name'],
                'email' => (string) $row['email'],
                'role'  => 'Author',
            ];
        }
        return $out;
    }
}
