<?php
/**
 * Slate — ContactSeeder (Phase 2A B5 backfill).
 *
 * Seeds the identity spine from the existing `customers` + `customer_auth_tokens`
 * data (identity doc §10 step 2). It is the backfill half of the dual-write
 * window: reads stay on the legacy path until this seed is validated, then B4
 * flips authentication over.
 *
 * Two invariants make it safe and reversible:
 *  - **ID-preservation (design A1):** each contact is created with `id` equal to
 *    the source `customers.id`, so every existing `customer_id` reference across
 *    the plugins is already a valid `contact_id` — zero plugin data rewritten.
 *  - **Idempotent:** a customer whose contact already exists is skipped, so the
 *    seed can be re-run (e.g. just before cutover) to pick up stragglers.
 *
 * Cross-tenant by design — it is a system backfill run from a migration, outside
 * request tenant scope. Existing password hashes are COPIED verbatim (never
 * re-hashed), so logins keep working unchanged.
 *
 * Layer: Services\Identity.
 */

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Data\Database;
use Slate\Data\QueryBuilder;
use Slate\Domain\Identity\Contact;
use Slate\Domain\Identity\EmailAddress;
use Slate\Domain\Identity\Identity;
use Slate\Domain\Identity\PhoneNumber;

final class ContactSeeder
{
    /**
     * Dry-run: report what a run would do, writing nothing.
     * @return array{customers:int,will_create_contacts:int,will_create_identities:int,guests:int,already_seeded:int,tokens:int}
     */
    public function plan(?int $onlyTenant = null): array
    {
        $where = $onlyTenant !== null ? ' WHERE tenant_id = ' . (int) $onlyTenant : '';
        $customers = (int) Database::value("SELECT COUNT(*) FROM customers{$where}");
        $withPw = (int) Database::value(
            "SELECT COUNT(*) FROM customers{$where}" . ($where === '' ? ' WHERE' : ' AND') .
            " password_hash IS NOT NULL AND password_hash <> ''"
        );
        $alreadySeeded = (int) Database::value(
            "SELECT COUNT(*) FROM customers cu JOIN contacts c ON c.id = cu.id" .
            ($onlyTenant !== null ? ' WHERE cu.tenant_id = ' . (int) $onlyTenant : '')
        );
        $tokens = (int) Database::value(
            "SELECT COUNT(*) FROM customer_auth_tokens" .
            ($onlyTenant !== null ? ' WHERE tenant_id = ' . (int) $onlyTenant : '')
        );
        return [
            'customers'             => $customers,
            'will_create_contacts'  => $customers - $alreadySeeded,
            'will_create_identities'=> $withPw,
            'guests'                => $customers - $withPw,
            'already_seeded'        => $alreadySeeded,
            'tokens'                => $tokens,
        ];
    }

    /**
     * Perform the idempotent seed. Returns counts of what it wrote.
     * @return array{created:int,identities:int,tokens:int,skipped:int}
     */
    public function run(?int $onlyTenant = null): array
    {
        $where = $onlyTenant !== null ? ' WHERE tenant_id = ' . (int) $onlyTenant : '';
        $created = 0; $identities = 0; $tokens = 0; $skipped = 0;

        foreach (Database::rows("SELECT * FROM customers{$where} ORDER BY id ASC") as $cu) {
            $contactId = (int) $cu['id'];
            if (Database::value('SELECT 1 FROM contacts WHERE id = ?', [$contactId]) !== null) {
                $skipped++;
                continue; // already seeded — idempotent
            }
            $tenantId = (int) $cu['tenant_id'];
            $email = EmailAddress::normalize((string) ($cu['email'] ?? ''));
            $phone = $cu['phone'] !== null ? PhoneNumber::normalize((string) $cu['phone']) : '';

            // Contact — id PRESERVED from customers.id (the BC linchpin).
            QueryBuilder::table('contacts')->insert([
                'id'            => $contactId,
                'tenant_id'     => $tenantId,
                'kind'          => Contact::KIND_PERSON,
                'display_name'  => $cu['name'] ?? null,
                'primary_email' => $email !== '' ? $email : null,
                'primary_phone' => $phone !== '' ? $phone : null,
                'status'        => Contact::STATUS_ACTIVE,   // suspension lives on the identity
                'created_at'    => $cu['created_at'],
            ]);
            $created++;

            if ($email !== '') {
                QueryBuilder::table('contact_emails')->insert([
                    'contact_id' => $contactId, 'tenant_id' => $tenantId, 'email' => $email,
                    'is_primary' => 1, 'verified' => !empty($cu['email_verified']) ? 1 : 0,
                ]);
            }
            if ($phone !== '') {
                QueryBuilder::table('contact_phones')->insert([
                    'contact_id' => $contactId, 'tenant_id' => $tenantId, 'phone' => $phone, 'is_primary' => 1,
                ]);
            }

            // Identity — only when a real login exists; hash COPIED, never re-hashed.
            if (!empty($cu['password_hash'])) {
                $identityId = QueryBuilder::table('identities')->insert([
                    'contact_id'     => $contactId,
                    'tenant_id'      => $tenantId,
                    'provider'       => Identity::PROVIDER_PASSWORD,
                    'credential_ref' => $email,
                    'password_hash'  => $cu['password_hash'],
                    'email_verified' => !empty($cu['email_verified']) ? 1 : 0,
                    'status'         => ($cu['status'] ?? '') === 'suspended'
                        ? Identity::STATUS_SUSPENDED : Identity::STATUS_ACTIVE,
                    'last_login_at'  => $cu['last_login_at'],
                    'created_at'     => $cu['created_at'],
                ]);
                $identities++;

                foreach (Database::rows('SELECT * FROM customer_auth_tokens WHERE customer_id = ?', [$contactId]) as $tk) {
                    QueryBuilder::table('identity_tokens')->insert([
                        'tenant_id'  => (int) $tk['tenant_id'],
                        'identity_id'=> $identityId,
                        'purpose'    => $tk['purpose'],
                        'token_hash' => $tk['token_hash'],
                        'expires_at' => $tk['expires_at'],
                        'used_at'    => $tk['used_at'],
                        'created_at' => $tk['created_at'],
                    ]);
                    $tokens++;
                }
            }
        }

        // Keep future auto-generated contacts clear of the preserved-id range.
        if ($onlyTenant === null) {
            $max = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM contacts');
            Database::get()->exec('ALTER TABLE contacts AUTO_INCREMENT = ' . ($max + 1));
        }

        return ['created' => $created, 'identities' => $identities, 'tokens' => $tokens, 'skipped' => $skipped];
    }

    /**
     * Idempotent UPSERT of a single customer into the spine — the dual-write
     * mirror used by Auth after any `customers` mutation (register / reset /
     * verify). Keeps contacts + identities in sync with `customers` so the legacy
     * table stays a valid rollback target. ID-preserving (contact.id == customer.id).
     * Best-effort: callers wrap it so a mirror failure never breaks the legacy write.
     */
    public function syncCustomer(int $customerId): void
    {
        $cu = Database::row('SELECT * FROM customers WHERE id = ?', [$customerId]);
        if ($cu === null) {
            return;
        }
        $tenantId = (int) $cu['tenant_id'];
        $email = EmailAddress::normalize((string) ($cu['email'] ?? ''));
        $phone = $cu['phone'] !== null ? PhoneNumber::normalize((string) $cu['phone']) : '';
        $verified = !empty($cu['email_verified']) ? 1 : 0;

        // contact (id preserved)
        if (Database::value('SELECT 1 FROM contacts WHERE id = ?', [$customerId]) === null) {
            QueryBuilder::table('contacts')->insert([
                'id'            => $customerId,
                'tenant_id'     => $tenantId,
                'kind'          => Contact::KIND_PERSON,
                'display_name'  => $cu['name'] ?? null,
                'primary_email' => $email !== '' ? $email : null,
                'primary_phone' => $phone !== '' ? $phone : null,
                'status'        => Contact::STATUS_ACTIVE,
                'created_at'    => $cu['created_at'] ?? slate_db_now(),
            ]);
        } else {
            Database::update('contacts', [
                'display_name'  => $cu['name'] ?? null,
                'primary_email' => $email !== '' ? $email : null,
                'primary_phone' => $phone !== '' ? $phone : null,
            ], 'id = ?', [$customerId]);
        }

        // primary email child
        if ($email !== '') {
            if (Database::value('SELECT 1 FROM contact_emails WHERE tenant_id = ? AND email = ?', [$tenantId, $email]) === null) {
                QueryBuilder::table('contact_emails')->insert([
                    'contact_id' => $customerId, 'tenant_id' => $tenantId, 'email' => $email,
                    'is_primary' => 1, 'verified' => $verified,
                ]);
            } else {
                Database::update('contact_emails', ['verified' => $verified], 'tenant_id = ? AND email = ?', [$tenantId, $email]);
            }
        }
        // primary phone child (insert if missing)
        if ($phone !== '' && Database::value('SELECT 1 FROM contact_phones WHERE contact_id = ? AND phone = ?', [$customerId, $phone]) === null) {
            QueryBuilder::table('contact_phones')->insert([
                'contact_id' => $customerId, 'tenant_id' => $tenantId, 'phone' => $phone, 'is_primary' => 1,
            ]);
        }

        // password identity (only when a real login exists); hash mirrored verbatim
        if (!empty($cu['password_hash'])) {
            $status = ($cu['status'] ?? '') === 'suspended' ? Identity::STATUS_SUSPENDED : Identity::STATUS_ACTIVE;
            $identityId = Database::value(
                "SELECT id FROM identities WHERE contact_id = ? AND provider = 'password'",
                [$customerId]
            );
            if ($identityId === null) {
                QueryBuilder::table('identities')->insert([
                    'contact_id'     => $customerId, 'tenant_id' => $tenantId,
                    'provider'       => Identity::PROVIDER_PASSWORD, 'credential_ref' => $email,
                    'password_hash'  => $cu['password_hash'], 'email_verified' => $verified,
                    'status'         => $status, 'last_login_at' => $cu['last_login_at'] ?? null,
                    'created_at'     => $cu['created_at'] ?? slate_db_now(),
                ]);
            } else {
                Database::update('identities', [
                    'credential_ref' => $email, 'password_hash' => $cu['password_hash'],
                    'email_verified' => $verified, 'status' => $status,
                ], 'id = ?', [(int) $identityId]);
            }
        }
    }
}
