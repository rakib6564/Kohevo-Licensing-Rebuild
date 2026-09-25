<?php
/**
 * Slate — ContactRepository (Phase 2A concrete service).
 *
 * The one way to read/write the canonical party across `contacts` +
 * `contact_emails` + `contact_phones`. Its `resolveOrCreate()` is the single
 * choke point (identity doc §5.1) that makes a sixth person-table impossible: a
 * guest checkout / booking / form submission *finds or creates* — it never spawns
 * a duplicate.
 *
 * Tenant scoping is centralized in scoped()/create() (every query is stamped with
 * the current tenant_id). Built on the Phase-1 QueryBuilder + TenantContext.
 *
 * Concrete class (not yet behind a Contracts\ interface — that formalization is
 * Phase 3, see docs/09-Roadmap/phase2a-identity-design.md A4). Layer: Services\Identity.
 */

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Data\QueryBuilder;
use Slate\Domain\Identity\Contact;
use Slate\Domain\Identity\EmailAddress;
use Slate\Domain\Identity\PhoneNumber;
use Slate\Tenancy\TenantContext;

final class ContactRepository
{
    public function __construct(private readonly TenantContext $tenants) {}

    // ── Reads (tenant-scoped) ─────────────────────────────────

    public function find(int $contactId): ?Contact
    {
        $row = $this->scoped('contacts')->where('id', $contactId)->first();
        return $row === null ? null : Contact::fromRow($row);
    }

    public function findByEmail(string $email): ?Contact
    {
        $normalized = EmailAddress::normalize($email);
        if ($normalized === '') {
            return null;
        }
        $row = $this->scoped('contact_emails')->select('contact_id')->where('email', $normalized)->first();
        return $row === null ? null : $this->find((int) $row['contact_id']);
    }

    public function findByPhone(string $phone): ?Contact
    {
        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === '') {
            return null;
        }
        $row = $this->scoped('contact_phones')->select('contact_id')->where('phone', $normalized)->first();
        return $row === null ? null : $this->find((int) $row['contact_id']);
    }

    // ── Writes (tenant-scoped) ────────────────────────────────

    /**
     * Create a new Contact plus its primary email/phone child rows.
     * $draft keys: display_name, email, phone, kind, status, email_verified.
     */
    public function create(array $draft): Contact
    {
        $tenantId = $this->tenants->id();
        $email = isset($draft['email']) ? EmailAddress::normalize((string) $draft['email']) : '';
        $phone = isset($draft['phone']) ? PhoneNumber::normalize((string) $draft['phone']) : '';

        $contactId = QueryBuilder::table('contacts')->insert([
            'tenant_id'     => $tenantId,
            'kind'          => $draft['kind'] ?? Contact::KIND_PERSON,
            'display_name'  => $draft['display_name'] ?? null,
            'primary_email' => $email !== '' ? $email : null,
            'primary_phone' => $phone !== '' ? $phone : null,
            'status'        => $draft['status'] ?? Contact::STATUS_ACTIVE,
        ]);

        if ($email !== '') {
            QueryBuilder::table('contact_emails')->insert([
                'contact_id' => $contactId,
                'tenant_id'  => $tenantId,
                'email'      => $email,
                'is_primary' => 1,
                'verified'   => !empty($draft['email_verified']) ? 1 : 0,
            ]);
        }
        if ($phone !== '') {
            QueryBuilder::table('contact_phones')->insert([
                'contact_id' => $contactId,
                'tenant_id'  => $tenantId,
                'phone'      => $phone,
                'is_primary' => 1,
            ]);
        }

        $contact = $this->find($contactId);
        if ($contact === null) {
            throw new \RuntimeException('ContactRepository::create failed to read back the new contact.');
        }
        return $contact;
    }

    /** Update whitelisted party columns on the contact. */
    public function update(int $contactId, array $patch): Contact
    {
        $allowed = array_intersect_key(
            $patch,
            array_flip(['display_name', 'primary_email', 'primary_phone', 'status', 'kind'])
        );
        if ($allowed !== []) {
            $this->scoped('contacts')->where('id', $contactId)->update($allowed);
        }
        $contact = $this->find($contactId);
        if ($contact === null) {
            throw new \RuntimeException("ContactRepository::update: contact {$contactId} not found in this tenant.");
        }
        return $contact;
    }

    /**
     * THE CHOKE POINT — match by normalized email, then phone, within the tenant;
     * otherwise create one Contact. $match keys: email, phone, display_name, kind,
     * status, email_verified. Match precedence: email > phone (identity doc §10 A8).
     */
    public function resolveOrCreate(array $match): Contact
    {
        $email = isset($match['email']) ? EmailAddress::normalize((string) $match['email']) : '';
        $phone = isset($match['phone']) ? PhoneNumber::normalize((string) $match['phone']) : '';

        if ($email !== '') {
            $hit = $this->findByEmail($email);
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($phone !== '') {
            $hit = $this->findByPhone($phone);
            if ($hit !== null) {
                return $hit;
            }
        }
        return $this->create($match);
    }

    // ── Internal ──────────────────────────────────────────────

    /** A tenant-scoped query builder for one of the contact tables. */
    private function scoped(string $table): QueryBuilder
    {
        return QueryBuilder::table($table)->where('tenant_id', $this->tenants->id());
    }
}
