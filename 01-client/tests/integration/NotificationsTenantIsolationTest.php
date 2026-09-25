<?php
/**
 * Notification tenant-isolation integration coverage.
 *
 * Uses only disposable probe rows in the restored staging database.
 */
declare(strict_types=1);

use Slate\Services\Notifications\Notifications;
use Slate\Tenancy\TenantContext;

$baseTenant = current_tenant_id();
$otherTenant = $baseTenant + slate_test_tenant(991234);
Notifications::ensureSchema();

$cleanup = static function (int $tenant) : void {
    Database::query('DELETE FROM slate_notifications WHERE tenant_id = ?', [$tenant]);
};

$cleanup($baseTenant);
$cleanup($otherTenant);

unit('notifications are tenant-isolated for feed, counts, lookup, mark, and delete', function () use ($baseTenant, $otherTenant, $cleanup): void {
    $tenants = new TenantContext();
    try {
        $mine = $tenants->runAs($baseTenant, function (): ?array {
            Notifications::add('Base tenant notification', ['body' => 'base']);
            return Notifications::recent(1)[0] ?? null;
        });
        $foreign = $tenants->runAs($otherTenant, function (): ?array {
            Notifications::add('Other tenant notification', ['body' => 'other']);
            return Notifications::recent(1)[0] ?? null;
        });

        assert_true(is_array($mine) && is_array($foreign));
        assert_true((int) $mine['id'] !== (int) $foreign['id']);
        assert_eq(1, $tenants->runAs($baseTenant, fn (): int => Notifications::unreadCount()));
        assert_eq(1, $tenants->runAs($otherTenant, fn (): int => Notifications::unreadCount()));
        assert_eq(null, $tenants->runAs($baseTenant, fn () => Notifications::get((int) $foreign['id'])));
        assert_eq((int) $mine['id'], (int) $tenants->runAs($baseTenant, fn () => Notifications::get((int) $mine['id']))['id']);

        $tenants->runAs($otherTenant, fn (): mixed => Notifications::markRead((int) $mine['id']));
        assert_eq(1, $tenants->runAs($baseTenant, fn (): int => Notifications::unreadCount()), 'foreign mark cannot change base row');
        $tenants->runAs($otherTenant, fn (): mixed => Notifications::delete((int) $mine['id']));
        assert_true($tenants->runAs($baseTenant, fn () => Notifications::get((int) $mine['id'])) !== null, 'foreign delete cannot remove base row');
    } finally {
        $cleanup($baseTenant);
        $cleanup($otherTenant);
    }
});

unit('notification bulk operations affect only the current tenant', function () use ($baseTenant, $otherTenant, $cleanup): void {
    $tenants = new TenantContext();
    try {
        $tenants->runAs($baseTenant, function (): void {
            Notifications::add('Base one');
            Notifications::add('Base two');
        });
        $tenants->runAs($otherTenant, function (): void {
            Notifications::add('Other one');
        });
        $tenants->runAs($baseTenant, fn (): mixed => Notifications::markAllRead());
        assert_eq(0, $tenants->runAs($baseTenant, fn (): int => Notifications::unreadCount()));
        assert_eq(1, $tenants->runAs($otherTenant, fn (): int => Notifications::unreadCount()));
        $tenants->runAs($baseTenant, fn (): mixed => Notifications::deleteAll());
        assert_eq(0, $tenants->runAs($baseTenant, fn (): int => Notifications::total()));
        assert_eq(1, $tenants->runAs($otherTenant, fn (): int => Notifications::total()));
    } finally {
        $cleanup($baseTenant);
        $cleanup($otherTenant);
    }
});
