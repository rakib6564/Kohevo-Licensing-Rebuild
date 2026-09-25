# BUILD BRIEF — Slate "Studio" plugin (dance/arts studio management)

You are building a new plugin `plugins/studio/` on the **Slate** platform. Read the full
runnable guide at `SKILL_studio_plugin.v2.md` (repo root) — it has copy-paste code reconciled
to the real APIs. This brief is the must-know summary + your first tasks.

## ENVIRONMENT (read carefully)
- Repo = **the LIVE production doc root**: `/home/rakilluy/greenlightinduction.rakibhasaan.com/slate`
  served at https://greenlightinduction.rakibhasaan.com/slate/ . Editing files here is editing production.
- **SAFETY:** a NEW plugin is inert until installed+activated. Keep `studio` INACTIVE (do not
  add it to the `plugins` table / do not activate) until fully tested. Its files on disk won't
  affect the running site while inactive.
- Host: CloudLinux/cPanel shared hosting, EA-PHP 8.4, MySQL DB (name in `.env` as `DB_NAME`). No composer
  install, no JS build. Flat PHP, custom kernel — NOT Laravel/WordPress.
- Backups live in `~/slate-backups/`. Take a fresh DB dump before any migration:
  `mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" --single-transaction | gzip > ~/slate-backups/pre_studio_$(date +%F_%H%M).sql.gz`
- EA-PHP quirk: multi-statement `php -r` is broken — write a temp `.php` script instead.

## PLATFORM FACTS (frozen, don't rediscover)
- Namespaces: `Slate\` → `src/` (PSR-4). Hooks = WordPress-style POSITIONAL dispatcher
  `Slate\Kernel\Event\Hook` (addAction/doAction/addFilter/applyFilters).
- Migrations: `Slate\Data\Migration` (up(Schema)/down(Schema)); files in **`db/migrations/NNNN_*.php`**
  (global, numeric-ordered); run `php bin/migrate {status|migrate|baseline|rollback}` (no `--plugin` flag).
  Schema builder `Slate\Data\Schema\Table` methods: `id, bigInt, int, tinyInt, boolean, string(n,len),
  char, text/mediumText/longText, json, date, datetime, timestamp, decimal, enum(n,[vals]),
  primary/unique/index(cols, ?name), drop`. Column modifiers: `->unsigned() ->nullable() ->default()
  ->useCurrent() ->after()`. **NO** `unsignedInteger/timestamps()/time()`. **`contacts.id` is BIGINT.**
- Tenancy: row-level `tenant_id`; `new Slate\Tenancy\TenantContext()` → `->id()/runAs()/withoutScope()`.
  Every query filters `tenant_id`. Data access = raw `Database::rows/row/value/insert/update/delete` OR
  `Slate\Data\Repository` (auto tenant-scoped).
- Money: `Slate\Support\Money` — `new Money(int $minor, string $currency)`; `->of/->times($f)/->minor/->toDecimal()`.
  Store money as INT cents columns.
- Identity (Phase 2A): members are core `contacts`/`customers` (id-preserved — `customer_id == contact_id`).
  Fetch: `$t=new TenantContext(); $c=(new Slate\Services\Identity\ContactRepository($t))->find($id)` → `Contact`
  (`->id ->displayName ->primaryEmail ->primaryPhone`; **NO `->meta`**). Auth is session-based:
  `Auth::requireCustomer(); Auth::customerId(); Auth::requirePerm('perm')` (emits 403 itself).
- Tests: dependency-free harness (NO PHPUnit): `tests/unit` (autoloader-only), `tests/integration`
  (boots app+DB), `tests/smoke.php`. Run `bash tests/run.sh`. Current baseline: **89 unit / 23 integration
  / 21 smoke** — MUST stay green.

## REAL PLUGIN APIS (guard every call with `class_exists`)
- **BookingAPI::createAppointment(array):** required `service_id, provider_id` (=`booking_providers.id`, NOT
  a contact id), `starts_at 'Y-m-d H:i:s', customer_name, customer_email` (validated, no `''`). Returns
  `['ok'=>true,'id'=>int,'ref'=>,'status'=>]` | `['ok'=>false,'error'=>]`. Ignores `ends_at/resource_id/meta`.
  Events (POSITIONAL): `booking_created($id,$serviceId,$providerId)`, `booking_cancelled($id)`,
  `booking_paid($apptId,$amountCents)`, `booking_status_changed($id,$status)`. Conflict:
  `freeResourceId(int $serviceId,int $startTs,int $endTs,int $partySize=1):?int`. **NO `createService`.**
- **MembershipAPI::** `plan(int):?array, plans(bool), subscription(int):?array,
  purchase(int $customerId,int $planId,bool $addInsurance=false):array, activateSubscription(int,array):bool,
  cancelSubscription(int,bool)`. **NO `createPlan/getPlan/getSubscription`. Membership emits NO hooks.**
  `membership_plans` has `plan_type ENUM('membership','insurance','course')` + `course_id` (loose→`booking_services.id`)
  + `session_quota`; `membership_subscriptions.customer_id == contact_id`. Insert plan rows directly.
- **StripePaymentAPI::** `isConfigured():bool, createCheckout(array $lineItems,array $opts):array,
  recordCharge(array):?int`. Emits `stripe_webhook_event($event array)`. **NO `stripe_payment_succeeded`.**
  Shared ledger: `stripepayment_charges` (source_plugin, source_id, amount_cents, status).
- **FormsAPI::** `getForm(string $slug):?array, upsertContact(int $tid,?string $email,array $data):void`.
  **NO `createDefinition`** — insert `forms_definitions` (`fields_json` JSON). Event:
  `forms_submitted($submissionId,$formId,$data)`. forms has a `signature` field type.
- **ContentBuilderAPI** = post-type CMS (`registerPostType/savePost/publish/getPost/listPosts`). Block reg via
  action `content_register_blocks(BlockRegistry::class)`. **NO `block()/createPage()`.**
- Core events you can use: `customer_registered($customerId)`, `customer_logged_in($customerId)`.
  Filters: `public_routes, customer_dashboard_widgets, admin_nav_items, admin_dashboard_widgets`.
- FKs: contact ids = **BIGINT**; `booking_*`/`membership_*` ids = **INT UNSIGNED**. Type each FK to its target.

## DISCIPLINE
- One logical batch = one commit on a branch `studio-plugin`. Run `bash tests/run.sh` before each commit;
  commit only when green. Additive + backward-compatible only.
- Verify every migration: dry-run (recording Schema), apply on dev, confirm tables, test `down()`/rollback.
- STOP and report before anything destructive, behavior-changing to existing plugins, or that would
  break the 89/23/21 test baseline.

## BATCH 0 (prerequisite — human does this)
Activate `booking` + `stripe-payment` in admin → Plugins; confirm Stripe keys. (These are installed but inactive.)

## BATCH 1 (your first deliverable)
1. `plugins/studio/plugin.json` (`requires_core ">=1.0.0"`), `Studio.php` (extends `\Plugin`; real hooks),
   `StudioAPI.php` (facade) — see SKILL v2 §1.
2. `db/migrations/0004_studio_core.php` — tables: `studio_contact_roles, studio_families,
   studio_family_members, studio_class_series, studio_class_occurrences, studio_enrollments,
   studio_attendance` (real Schema API, BIGINT contact FKs) — see SKILL v2 §2.
3. StudioAPI core: `createFamily, assignContactRole, getFamilyByParent, familyStudentIds,
   createClassSeries, generateOccurrences` (weekly), `getActiveClassSeries`.
4. Tests: `tests/unit` (tuition Money math), `tests/integration` (create family→series→enroll, throwaway
   tenant, cleaned up).
**ACCEPTANCE:** `php -l` clean; migration dry-run + applied + rollback OK; `bash tests/run.sh` green;
studio plugin still INACTIVE; nothing else changed.

**Design-note-first:** before writing code, produce a 1-page plan (tables + StudioAPI signatures + how
studio maps to booking/membership) and confirm it, THEN implement Batch 1.

## FILES TO READ FIRST (real templates)
`src/Data/Schema/Table.php` · `src/Support/Money.php` · `src/Services/Identity/ContactRepository.php` ·
`plugins/booking/BookingAPI.php` · `plugins/membership/MembershipAPI.php` ·
`db/migrations/0002_identity_core.php` (migration template) ·
`tests/integration/ContactRepositoryTest.php` (test template) · `SKILL_studio_plugin.v2.md` (full guide).
