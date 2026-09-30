<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 3 Application Boundary.
 *
 * Dependency-free (no database): exercises `StudioActor` and
 * `DataProviderRegistry` (against fake in-memory providers, not the real
 * Booking/Membership/Forms-backed ones, which need real business tables and
 * are covered by the Phase 3 integration suite) via the autoloader only.
 *
 * Verifies:
 *   1. StudioActor: guest vs authenticated, permission checks, super-admin bypass.
 *   2. DataProviderRegistry: duplicate/invalid key rejection, unknown-key
 *      fail-closed, entitlement/permission/parameter-validation enforcement,
 *      and hard result-count bounding — independent of what any real
 *      provider's own query does.
 *   3. ValidatedDocument: the only way to obtain one is through validation.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioP3UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioEntitlementException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Provider\DataProviderInterface;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

/** Fake provider: no entitlement required, returns a fixed row set. */
final class _FakeOpenProvider implements DataProviderInterface
{
    public function __construct(private readonly array $rows = []) {}
    public function key(): string { return 'fake.open'; }
    public function parameterSchema(): FieldSchema { return FieldSchema::define([]); }
    public function requiredEntitlement(): ?string { return null; }
    public function requiredPermission(): string { return StudioPermissions::VIEW; }
    public function maxResults(): int { return 3; }
    public function execute(TenantContext $tenants, array $params): array { return $this->rows; }
}

/** Fake provider: requires the 'widgets' entitlement and a required 'id' param. */
final class _FakeGatedProvider implements DataProviderInterface
{
    public function key(): string { return 'fake.gated'; }
    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'id', 'type' => 'number', 'integer_only' => true, 'required' => true, 'min' => 1],
        ]);
    }
    public function requiredEntitlement(): ?string { return 'widgets'; }
    public function requiredPermission(): string { return StudioPermissions::ADMIN; }
    public function maxResults(): int { return 10; }
    public function execute(TenantContext $tenants, array $params): array { return [['id' => $params['id']]]; }
}

// ── 1. StudioActor ───────────────────────────────────────────────────────────

unit('phase3 application boundary: StudioActor guest vs authenticated, permission checks, super-admin bypass', function (): void {
    $guest = StudioActor::guest();
    assert_false($guest->isAuthenticated());
    assert_false($guest->can(StudioPermissions::VIEW));

    $viewer = StudioActor::authenticated(7, [StudioPermissions::VIEW]);
    assert_true($viewer->isAuthenticated());
    assert_true($viewer->can(StudioPermissions::VIEW));
    assert_false($viewer->can(StudioPermissions::EDIT), 'a view-only actor must not be able to edit');
    assert_false($viewer->can(StudioPermissions::PUBLISH));

    $editor = StudioActor::authenticated(8, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
    assert_true($editor->can(StudioPermissions::EDIT));
    assert_false($editor->can(StudioPermissions::PUBLISH), 'an editor must not be able to publish (edit cannot publish)');

    $publisher = StudioActor::authenticated(9, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);
    assert_true($publisher->can(StudioPermissions::PUBLISH));
    assert_false($publisher->can(StudioPermissions::ADMIN), 'admin-only operations must remain protected from a non-admin actor');

    $superAdmin = StudioActor::authenticated(1, [], true);
    assert_true($superAdmin->can(StudioPermissions::ADMIN), 'super admin bypasses the granted-permission list');
    assert_true($superAdmin->can('studio-builder.anything_not_in_the_fixed_set'), 'super admin bypass is unconditional');

    assert_throws(\InvalidArgumentException::class, function (): void {
        StudioActor::authenticated(0, [StudioPermissions::VIEW]);
    }, 'authenticated() must reject a non-positive userId');
});

// ── 2. DataProviderRegistry ──────────────────────────────────────────────────

unit('phase3 application boundary: DataProviderRegistry rejects invalid/duplicate keys', function (): void {
    $registry = new DataProviderRegistry();
    $registry->register(new _FakeOpenProvider());

    assert_true($registry->has('fake.open'));
    assert_null($registry->get('fake.does_not_exist'));

    assert_throws(\InvalidArgumentException::class, function () use ($registry): void {
        $registry->register(new _FakeOpenProvider());
    }, 'duplicate provider key registration must fail closed');
});

unit('phase3 application boundary: DataProviderRegistry::resolve() enforces entitlement, permission, params, and bounds results', function (): void {
    $tenants = new TenantContext();
    $registry = new DataProviderRegistry();
    $registry->register(new _FakeOpenProvider([['a' => 1], ['a' => 2], ['a' => 3], ['a' => 4], ['a' => 5]]));
    $registry->register(new _FakeGatedProvider());

    $alwaysAllow = static fn(?string $x): bool => true;
    $neverAllow  = static fn(?string $x): bool => false;

    // Unknown provider key fails closed.
    assert_throws(StudioNotFoundException::class, function () use ($registry, $tenants, $alwaysAllow): void {
        $registry->resolve('fake.does_not_exist', [], $tenants, $alwaysAllow, $alwaysAllow);
    });

    // Open provider (no entitlement) with permission granted succeeds and is bounded to maxResults()=3.
    $rows = $registry->resolve('fake.open', [], $tenants, $alwaysAllow, $alwaysAllow);
    assert_eq(3, count($rows), 'result must be truncated to maxResults() regardless of how many rows the provider returned');

    // Permission denied fails closed even when entitlement (none required) would pass.
    assert_throws(StudioAuthorizationException::class, function () use ($registry, $tenants, $alwaysAllow, $neverAllow): void {
        $registry->resolve('fake.open', [], $tenants, $alwaysAllow, $neverAllow);
    });

    // Gated provider: entitlement denied fails closed even when permission would pass.
    assert_throws(StudioEntitlementException::class, function () use ($registry, $tenants, $neverAllow, $alwaysAllow): void {
        $registry->resolve('fake.gated', ['id' => 1], $tenants, $neverAllow, $alwaysAllow);
    });

    // Gated provider: entitled + permitted, but missing required param, fails closed.
    assert_throws(StudioValidationException::class, function () use ($registry, $tenants, $alwaysAllow): void {
        $registry->resolve('fake.gated', [], $tenants, $alwaysAllow, $alwaysAllow);
    });

    // Gated provider: entitled + permitted + valid params succeeds.
    $gatedRows = $registry->resolve('fake.gated', ['id' => 42], $tenants, $alwaysAllow, $alwaysAllow);
    assert_eq(1, count($gatedRows));
    assert_eq(42, $gatedRows[0]['id']);
});

// ── 3. ValidatedDocument ─────────────────────────────────────────────────────

unit('phase3 application boundary: ValidatedDocument is only constructible through validation', function (): void {
    $registry = BlockRegistry::withCoreFoundationBlocks();

    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Hello');
    $validated = ValidatedDocument::from($doc, $registry);
    assert_eq('Hello', $validated->toArray()['seo']['title']);

    $broken = $doc;
    unset($broken['schema_version']);
    assert_throws(StudioValidationException::class, function () use ($broken, $registry): void {
        ValidatedDocument::from($broken, $registry);
    }, 'ValidatedDocument::from() must propagate validation failure rather than silently accept a malformed document');
});

if (!empty($studioP3UnitStandalone)) {
    exit(unit_summary());
}
