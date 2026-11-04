<?php
/**
 * StudioAPI — the Studio plugin's public facade.
 *
 * Global class (matching BookingAPI / MembershipAPI convention) so other plugins
 * can call it behind a class_exists('StudioAPI') guard. Every method is tenant-
 * scoped via current_tenant_id() and talks to the raw Database:: helpers against
 * the 0005_studio_core tables.
 *
 * Batch 1 scope: families + roles, class-series CRUD, weekly occurrence
 * generation, and studio-local enrollment. Booking/membership/Stripe side
 * effects are deliberately absent here — they arrive in Batches 2–3 via the
 * adapters, keyed off the (nullable) booking_service_id / membership_plan_id /
 * subscription_id columns.
 */

declare(strict_types=1);

use Slate\Module\Studio\Domain\Audience;
use Slate\Module\Studio\Domain\DiscountPolicy;
use Slate\Module\Studio\Domain\FeeSchedule;
use Slate\Module\Studio\Domain\HolidayCalendar;
use Slate\Module\Studio\Domain\ReminderPolicy;
use Slate\Module\Studio\Domain\ScheduleConflict;
use Slate\Module\Studio\Domain\SeasonShift;
use Slate\Module\Studio\Domain\TuitionCalculator;
use Slate\Module\Studio\Domain\TuitionPlan;
use Slate\Support\Money;

class StudioAPI
{
    // ── Families / roles ─────────────────────────────────────────

    /**
     * Create a family anchored on a parent, with the given student contacts as
     * children. Idempotently tags the parent as `parent` and each student as
     * `student`. Returns the new studio_families.id.
     *
     * @param int[] $studentIds contacts.id of the children
     */
    public static function createFamily(int $primaryParentId, array $studentIds): int
    {
        $tid = current_tenant_id();

        $familyId = Database::insert('studio_families', [
            'tenant_id'         => $tid,
            'primary_parent_id' => $primaryParentId,
        ]);

        self::assignContactRole($primaryParentId, 'parent');

        foreach ($studentIds as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }
            Database::insert('studio_family_members', [
                'tenant_id'  => $tid,
                'family_id'  => $familyId,
                'contact_id' => $sid,
                'relation'   => 'child',
            ]);
            self::assignContactRole($sid, 'student');
        }

        return $familyId;
    }

    /**
     * Tag a contact with a studio role. Idempotent: a role a contact already
     * holds is a no-op (the table has a unique key on tenant+contact+role).
     */
    public static function assignContactRole(int $contactId, string $role): void
    {
        $allowed = ['student', 'parent', 'instructor', 'guardian'];
        if (!in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown studio role: {$role}");
        }

        $tid = current_tenant_id();
        $exists = Database::value(
            'SELECT id FROM studio_contact_roles WHERE tenant_id = ? AND contact_id = ? AND role = ?',
            [$tid, $contactId, $role]
        );
        if ($exists) {
            return;
        }

        Database::insert('studio_contact_roles', [
            'tenant_id'  => $tid,
            'contact_id' => $contactId,
            'role'       => $role,
        ]);
    }

    /** The family a given parent anchors, or null. */
    public static function getFamilyByParent(int $parentId): ?array
    {
        return Database::row(
            'SELECT * FROM studio_families WHERE tenant_id = ? AND primary_parent_id = ? LIMIT 1',
            [current_tenant_id(), $parentId]
        );
    }

    /**
     * The child contact ids in a family.
     *
     * @return int[]
     */
    public static function familyStudentIds(int $familyId): array
    {
        $rows = Database::rows(
            "SELECT contact_id FROM studio_family_members
              WHERE tenant_id = ? AND family_id = ? AND relation = 'child'
           ORDER BY contact_id",
            [current_tenant_id(), $familyId]
        );
        return array_map(static fn ($r) => (int) $r['contact_id'], $rows);
    }

    // ── Class series ─────────────────────────────────────────────

    /**
     * Create a class series. Required keys: name, style, instructor_id,
     * day_of_week, start_time, end_time, session_start, session_end,
     * price_cents. Everything else falls back to a sensible default.
     *
     * Returns the new studio_class_series.id.
     */
    public static function createClassSeries(array $data): int
    {
        foreach (['name', 'style', 'instructor_id', 'day_of_week', 'start_time', 'end_time', 'session_start', 'session_end', 'price_cents'] as $req) {
            if (!array_key_exists($req, $data) || $data[$req] === '' || $data[$req] === null) {
                throw new \InvalidArgumentException("createClassSeries: missing required field '{$req}'");
            }
        }

        $dow = (int) $data['day_of_week'];
        if ($dow < 0 || $dow > 6) {
            throw new \InvalidArgumentException('createClassSeries: day_of_week must be 0 (Sun) … 6 (Sat)');
        }

        $tid = current_tenant_id();
        $row = [
            'tenant_id'          => $tid,
            'name'               => (string) $data['name'],
            'style'              => (string) $data['style'],
            'level'              => $data['level'] ?? null,
            'age_min'            => (int) ($data['age_min'] ?? 0),
            'age_max'            => (int) ($data['age_max'] ?? 99),
            'capacity'           => (int) ($data['capacity'] ?? 20),
            'instructor_id'      => (int) $data['instructor_id'],
            'room_id'            => isset($data['room_id']) ? (int) $data['room_id'] : null,
            'booking_service_id' => isset($data['booking_service_id']) ? (int) $data['booking_service_id'] : null,
            'membership_plan_id' => isset($data['membership_plan_id']) ? (int) $data['membership_plan_id'] : null,
            'day_of_week'        => $dow,
            'start_time'         => (string) $data['start_time'],
            'end_time'           => (string) $data['end_time'],
            'session_start'      => (string) $data['session_start'],
            'session_end'        => (string) $data['session_end'],
            'price_cents'        => (int) $data['price_cents'],
            'currency'           => (string) ($data['currency'] ?? 'USD'),
            'is_pro_rated'       => !empty($data['is_pro_rated'] ?? true) ? 1 : 0,
            'is_active'          => array_key_exists('is_active', $data) ? ((int) (bool) $data['is_active']) : 1,
        ];

        $meta = self::classMetaFrom($data);
        if ($meta !== []) {
            $row['meta'] = json_encode($meta);
        }

        return Database::insert('studio_class_series', $row);
    }

    /**
     * The descriptive fields a class carries beyond its schedule.
     *
     * These live in `meta` rather than as columns because they are all
     * optional prose that nothing queries or joins on — a description is read,
     * never filtered. `image` was already stored this way; adding a special
     * case per field would have meant four near-identical merge blocks, so the
     * handling is generalised instead.
     */
    public const CLASS_META_KEYS = ['image', 'description', 'location', 'virtual_url'];

    /**
     * Pull the known meta keys out of an input array, dropping blanks.
     *
     * @return array<string,string>
     */
    private static function classMetaFrom(array $data): array
    {
        $out = [];
        foreach (self::CLASS_META_KEYS as $k) {
            if (!array_key_exists($k, $data)) { continue; }
            $v = trim((string) $data[$k]);
            if ($v !== '') { $out[$k] = $v; }
        }
        return $out;
    }

    /** One meta value off a series row, or ''. */
    public static function classMeta(array $seriesRow, string $key): string
    {
        if (empty($seriesRow['meta'])) { return ''; }
        $m = json_decode((string) $seriesRow['meta'], true);
        return is_array($m) ? trim((string) ($m[$key] ?? '')) : '';
    }

    /**
     * The join link for an online class — and ONLY to someone entitled to it.
     *
     * A meeting URL on a public class page is an open door: anyone reading the
     * catalog could sit in on a children's dance class. So this asks who is
     * looking, and the public view never renders the raw value.
     */
    public static function classJoinUrl(array $seriesRow, ?int $parentContactId): string
    {
        $url = self::classMeta($seriesRow, 'virtual_url');
        if ($url === '' || $parentContactId === null || $parentContactId <= 0) { return ''; }

        $fam = self::getFamilyByParent($parentContactId);
        if ($fam === null) { return ''; }

        $ids = self::familyStudentIds((int) $fam['id']);
        if (!$ids) { return ''; }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $enrolled = (int) Database::value(
            "SELECT COUNT(*) FROM studio_enrollments
              WHERE tenant_id = ? AND series_id = ? AND student_id IN ($ph)
                AND status IN ('active','trial')",
            array_merge([current_tenant_id(), (int) $seriesRow['id']], $ids)
        );
        return $enrolled > 0 ? $url : '';
    }

    /** A single series row, or null. Tenant-scoped. */
    public static function getClassSeries(int $seriesId): ?array
    {
        return Database::row(
            'SELECT * FROM studio_class_series WHERE tenant_id = ? AND id = ? LIMIT 1',
            [current_tenant_id(), $seriesId]
        );
    }

    /** Featured image URL for a class-series row (stored in meta.image), or ''. */
    public static function classImage(array $seriesRow): string
    {
        return self::classMeta($seriesRow, 'image');
    }

    /**
     * Generate the weekly occurrences for a series across its session window,
     * one per week on the series' day_of_week. Idempotent: dates that already
     * have an occurrence row are skipped. Returns the number of NEW rows created.
     */
    public static function generateOccurrences(int $seriesId): int
    {
        $series = self::getClassSeries($seriesId);
        if ($series === null) {
            throw new \InvalidArgumentException("generateOccurrences: unknown series {$seriesId}");
        }

        $tid    = current_tenant_id();
        $dow    = (int) $series['day_of_week'];
        $cursor = new \DateTimeImmutable((string) $series['session_start']);
        $end    = new \DateTimeImmutable((string) $series['session_end']);

        // Advance to the first matching weekday within the window.
        $guard = 0;
        while ((int) $cursor->format('w') !== $dow && $guard++ < 7) {
            $cursor = $cursor->modify('+1 day');
        }

        // Which dates already exist (avoid an INSERT-per-date round trip on the check).
        $existing = [];
        foreach (Database::rows(
            'SELECT occurrence_date FROM studio_class_occurrences WHERE tenant_id = ? AND series_id = ?',
            [$tid, $seriesId]
        ) as $r) {
            $existing[(string) $r['occurrence_date']] = true;
        }

        $holidays = self::holidayCalendar();

        $created = 0;
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            // A closed date produces no lesson at all, rather than a lesson
            // that is then cancelled: generating Christmas Day and immediately
            // cancelling it would put a cancellation in every parent's
            // schedule for a day the studio was never open.
            if ($holidays->isHoliday($date)) {
                $cursor = $cursor->modify('+7 days');
                continue;
            }
            if (!isset($existing[$date])) {
                Database::insert('studio_class_occurrences', [
                    'tenant_id'       => $tid,
                    'series_id'       => $seriesId,
                    'occurrence_date' => $date,
                    'start_time'      => (string) $series['start_time'],
                    'end_time'        => (string) $series['end_time'],
                    'status'          => 'scheduled',
                ]);
                $created++;
            }
            $cursor = $cursor->modify('+7 days');
        }

        return $created;
    }

    /**
     * Active series for the current tenant, newest first.
     *
     * @return array[]
     */
    public static function getActiveClassSeries(): array
    {
        return Database::rows(
            'SELECT * FROM studio_class_series WHERE tenant_id = ? AND is_active = 1 ORDER BY id DESC',
            [current_tenant_id()]
        );
    }

    /**
     * All class series (active first) with instructor name + occurrence and
     * active-enrollment counts — for the admin Classes list.
     *
     * @return array[]
     */
    public static function getClassSeriesWithStats(): array
    {
        return Database::rows(
            "SELECT s.*, c.display_name AS instructor_name,
                    (SELECT COUNT(*) FROM studio_class_occurrences o
                      WHERE o.tenant_id = s.tenant_id AND o.series_id = s.id) AS occurrence_count,
                    (SELECT COUNT(*) FROM studio_enrollments e
                      WHERE e.tenant_id = s.tenant_id AND e.series_id = s.id
                        AND e.status IN ('active','trial')) AS enrolled_count
               FROM studio_class_series s
               LEFT JOIN contacts c ON c.id = s.instructor_id
              WHERE s.tenant_id = ?
           ORDER BY s.is_active DESC, s.id DESC",
            [current_tenant_id()]
        );
    }

    /**
     * Contacts tagged as instructors in this studio (for pickers).
     *
     * @return array[] rows of {id, display_name}
     */
    public static function getInstructors(): array
    {
        return Database::rows(
            "SELECT DISTINCT c.id, c.display_name
               FROM studio_contact_roles r
               JOIN contacts c ON c.id = r.contact_id
              WHERE r.tenant_id = ? AND r.role = 'instructor'
           ORDER BY c.display_name",
            [current_tenant_id()]
        );
    }

    /**
     * Families with their parent name and child members — for the admin
     * Families list. Each row gains a 'students' array of {id, display_name}.
     *
     * @return array[]
     */
    public static function getFamiliesWithMembers(): array
    {
        $tid  = current_tenant_id();
        $fams = Database::rows(
            "SELECT f.*, c.display_name AS parent_name, c.primary_email AS parent_email
               FROM studio_families f
               LEFT JOIN contacts c ON c.id = f.primary_parent_id
              WHERE f.tenant_id = ?
           ORDER BY f.id DESC",
            [$tid]
        );
        foreach ($fams as &$f) {
            $f['students'] = Database::rows(
                "SELECT c.id, c.display_name
                   FROM studio_family_members m
                   JOIN contacts c ON c.id = m.contact_id
                  WHERE m.tenant_id = ? AND m.family_id = ? AND m.relation = 'child'
               ORDER BY c.display_name",
                [$tid, (int) $f['id']]
            );
        }
        unset($f);
        return $fams;
    }

    // ── CRUD: update / delete (edit + bulk actions) ──────────────

    /** Patch whitelisted class-series columns. Tenant-scoped. */
    public static function updateClassSeries(int $id, array $data): bool
    {
        $allowed = [
            'name', 'style', 'level', 'age_min', 'age_max', 'capacity', 'instructor_id',
            'room_id', 'booking_service_id', 'membership_plan_id', 'day_of_week',
            'start_time', 'end_time', 'session_start', 'session_end', 'price_cents',
            'currency', 'is_pro_rated', 'is_active',
        ];
        $patch = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) { $patch[$k] = $data[$k]; }
        }

        // Descriptive fields are merged into the meta JSON (no columns — see
        // CLASS_META_KEYS). Merged, not replaced: an edit form that posts only
        // the description must not wipe the featured image. A key posted empty
        // is a deliberate clear, so it is unset rather than stored blank.
        $touched = array_intersect(self::CLASS_META_KEYS, array_keys($data));
        if ($touched !== []) {
            $cur  = self::getClassSeries($id);
            $meta = ($cur && !empty($cur['meta'])) ? (json_decode((string) $cur['meta'], true) ?: []) : [];
            foreach ($touched as $k) {
                $v = trim((string) $data[$k]);
                if ($v === '') { unset($meta[$k]); } else { $meta[$k] = $v; }
            }
            $patch['meta'] = $meta !== [] ? json_encode($meta) : null;
        }

        if ($patch === []) { return false; }
        $patch['updated_at'] = slate_db_now();
        Database::update('studio_class_series', $patch, 'tenant_id = ? AND id = ?', [current_tenant_id(), $id]);
        return true;
    }

    public static function setClassSeriesActive(int $id, bool $active): bool
    {
        return self::updateClassSeries($id, ['is_active' => $active ? 1 : 0]);
    }

    /** Delete a series and its dependents (occurrences + enrollments). Contacts are left intact. */
    public static function deleteClassSeries(int $id): bool
    {
        $tid = current_tenant_id();
        if (self::getClassSeries($id) === null) { return false; }
        Database::delete('studio_enrollments', 'tenant_id = ? AND series_id = ?', [$tid, $id]);
        Database::delete('studio_class_occurrences', 'tenant_id = ? AND series_id = ?', [$tid, $id]);
        Database::delete('studio_class_series', 'tenant_id = ? AND id = ?', [$tid, $id]);
        return true;
    }

    /** A single family row, or null. */
    public static function getFamily(int $id): ?array
    {
        return Database::row(
            'SELECT * FROM studio_families WHERE tenant_id = ? AND id = ? LIMIT 1',
            [current_tenant_id(), $id]
        );
    }

    /** Delete a family + its membership rows. Student/parent contacts are shared identity and kept. */
    public static function deleteFamily(int $id): bool
    {
        $tid = current_tenant_id();
        if (self::getFamily($id) === null) { return false; }
        Database::delete('studio_family_members', 'tenant_id = ? AND family_id = ?', [$tid, $id]);
        Database::delete('studio_families', 'tenant_id = ? AND id = ?', [$tid, $id]);
        return true;
    }

    /** Add a student (contact) to a family as a child; idempotent. Tags the student role. */
    public static function addStudentToFamily(int $familyId, int $contactId): void
    {
        $tid = current_tenant_id();
        $exists = Database::value(
            'SELECT id FROM studio_family_members WHERE tenant_id = ? AND family_id = ? AND contact_id = ?',
            [$tid, $familyId, $contactId]
        );
        if (!$exists) {
            Database::insert('studio_family_members', [
                'tenant_id'  => $tid,
                'family_id'  => $familyId,
                'contact_id' => $contactId,
                'relation'   => 'child',
            ]);
        }
        self::assignContactRole($contactId, 'student');
    }

    /** Remove a student from a family (keeps the contact). */
    public static function removeStudentFromFamily(int $familyId, int $contactId): bool
    {
        Database::delete(
            'studio_family_members',
            'tenant_id = ? AND family_id = ? AND contact_id = ?',
            [current_tenant_id(), $familyId, $contactId]
        );
        return true;
    }

    /** Hard-delete an enrollment row. */
    public static function deleteEnrollment(int $id): bool
    {
        Database::delete('studio_enrollments', 'tenant_id = ? AND id = ?', [current_tenant_id(), $id]);
        return true;
    }

    // ── Students (contacts with role=student) ────────────────────

    /**
     * Roster of students with their family parent, active-enrollment count and
     * profile JSON (stored on the student's role row). One row per student.
     *
     * @return array[]
     */
    public static function getStudents(): array
    {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT c.id, c.display_name, c.primary_email, r.meta AS profile_meta,
                    (SELECT COUNT(*) FROM studio_enrollments e
                      WHERE e.tenant_id = r.tenant_id AND e.student_id = c.id
                        AND e.status IN ('active','trial')) AS enrolled_count,
                    (SELECT m.family_id FROM studio_family_members m
                      WHERE m.tenant_id = r.tenant_id AND m.contact_id = c.id LIMIT 1) AS family_id,
                    (SELECT c2.display_name FROM studio_family_members m
                       JOIN studio_families f ON f.id = m.family_id
                       JOIN contacts c2 ON c2.id = f.primary_parent_id
                      WHERE m.tenant_id = r.tenant_id AND m.contact_id = c.id LIMIT 1) AS parent_name
               FROM studio_contact_roles r
               JOIN contacts c ON c.id = r.contact_id
              WHERE r.tenant_id = ? AND r.role = 'student'
           ORDER BY c.display_name",
            [$tid]
        );
    }

    /** A student's studio profile (JSON on the role row): dob, skill, medical, allergies, emergency_*. */
    public static function getStudentProfile(int $contactId): array
    {
        $raw = Database::value(
            "SELECT meta FROM studio_contact_roles WHERE tenant_id = ? AND contact_id = ? AND role = 'student'",
            [current_tenant_id(), $contactId]
        );
        $data = $raw ? json_decode((string) $raw, true) : [];
        return is_array($data) ? $data : [];
    }

    /** Store a student's studio profile JSON (ensures the student role exists first). */
    public static function saveStudentProfile(int $contactId, array $profile): void
    {
        self::assignContactRole($contactId, 'student');
        Database::update(
            'studio_contact_roles',
            ['meta' => json_encode($profile)],
            "tenant_id = ? AND contact_id = ? AND role = 'student'",
            [current_tenant_id(), $contactId]
        );
    }

    /** Detail bundle for one student: contact + profile + family + enrolled classes. */
    public static function getStudentDetail(int $contactId): ?array
    {
        $tid = current_tenant_id();
        $c = Database::row('SELECT id, display_name, primary_email, primary_phone FROM contacts WHERE tenant_id = ? AND id = ?', [$tid, $contactId]);
        if ($c === null) { return null; }
        $c['profile']  = self::getStudentProfile($contactId);
        $c['family_id'] = (int) (Database::value(
            'SELECT family_id FROM studio_family_members WHERE tenant_id = ? AND contact_id = ? LIMIT 1',
            [$tid, $contactId]
        ) ?? 0);
        $c['enrollments'] = Database::rows(
            "SELECT e.status, s.name AS series_name
               FROM studio_enrollments e JOIN studio_class_series s ON s.id = e.series_id
              WHERE e.tenant_id = ? AND e.student_id = ? ORDER BY e.id DESC",
            [$tid, $contactId]
        );
        return $c;
    }

    /** Remove a student from the studio: drop enrollments, family links and the student role. Keeps the contact. */
    public static function removeStudent(int $contactId): bool
    {
        $tid = current_tenant_id();
        Database::delete('studio_enrollments', 'tenant_id = ? AND student_id = ?', [$tid, $contactId]);
        Database::delete('studio_family_members', 'tenant_id = ? AND contact_id = ?', [$tid, $contactId]);
        Database::delete('studio_contact_roles', "tenant_id = ? AND contact_id = ? AND role = 'student'", [$tid, $contactId]);
        return true;
    }

    /** Families as {id, label} for pickers (parent name + student count). */
    public static function getFamilyOptions(): array
    {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT f.id, COALESCE(c.display_name, CONCAT('Family #', f.id)) AS label
               FROM studio_families f LEFT JOIN contacts c ON c.id = f.primary_parent_id
              WHERE f.tenant_id = ? ORDER BY label",
            [$tid]
        );
    }

    // ── Instructors (contacts with role=instructor) ──────────────

    /**
     * Instructor roster with class-taught counts and profile JSON (bio, phone,
     * booking_provider_id) stored on the instructor role row.
     *
     * @return array[]
     */
    public static function getInstructorsDetailed(): array
    {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT c.id, c.display_name, c.primary_email, r.meta AS profile_meta,
                    (SELECT COUNT(*) FROM studio_class_series s
                      WHERE s.tenant_id = r.tenant_id AND s.instructor_id = c.id) AS class_count
               FROM studio_contact_roles r
               JOIN contacts c ON c.id = r.contact_id
              WHERE r.tenant_id = ? AND r.role = 'instructor'
           ORDER BY c.display_name",
            [$tid]
        );
    }

    /** An instructor's profile JSON (bio, phone, booking_provider_id). */
    public static function getInstructorProfile(int $contactId): array
    {
        $raw = Database::value(
            "SELECT meta FROM studio_contact_roles WHERE tenant_id = ? AND contact_id = ? AND role = 'instructor'",
            [current_tenant_id(), $contactId]
        );
        $data = $raw ? json_decode((string) $raw, true) : [];
        return is_array($data) ? $data : [];
    }

    /** Store an instructor's profile JSON (ensures the instructor role exists first). */
    public static function saveInstructorProfile(int $contactId, array $profile): void
    {
        self::assignContactRole($contactId, 'instructor');
        Database::update(
            'studio_contact_roles',
            ['meta' => json_encode($profile)],
            "tenant_id = ? AND contact_id = ? AND role = 'instructor'",
            [current_tenant_id(), $contactId]
        );
    }

    /** Detail bundle for one instructor: contact + profile + classes taught. */
    public static function getInstructorDetail(int $contactId): ?array
    {
        $tid = current_tenant_id();
        $c = Database::row('SELECT id, display_name, primary_email, primary_phone FROM contacts WHERE tenant_id = ? AND id = ?', [$tid, $contactId]);
        if ($c === null) { return null; }
        $c['profile'] = self::getInstructorProfile($contactId);
        $c['classes'] = Database::rows(
            "SELECT id, name, is_active FROM studio_class_series WHERE tenant_id = ? AND instructor_id = ? ORDER BY name",
            [$tid, $contactId]
        );
        return $c;
    }

    /** Remove the instructor role from a contact (keeps the contact; classes keep their instructor_id). */
    public static function removeInstructor(int $contactId): bool
    {
        Database::delete('studio_contact_roles', "tenant_id = ? AND contact_id = ? AND role = 'instructor'", [current_tenant_id(), $contactId]);
        return true;
    }

    /**
     * Booking providers as {id, name} for the optional instructor↔provider link.
     * Returns [] when the booking plugin's table is absent (booking inactive).
     *
     * @return array[]
     */
    public static function getBookingProviderOptions(): array
    {
        try {
            $has = Database::value(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_providers'"
            );
            if (!$has) { return []; }
            return Database::rows(
                'SELECT id, name FROM booking_providers WHERE tenant_id = ? ORDER BY name',
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Enrollment (studio-local; billing wired in Batch 3) ──────

    /**
     * Enroll a student into a series (studio-local row only — no booking or
     * membership side effects yet). If the series is at capacity the enrollment
     * lands on the waitlist unless the caller forces a status via $opts['status'].
     * Re-enrolling an already-active student is a no-op that returns the
     * existing row.
     *
     * @return array{ok:bool,id:int,status:string,existing?:bool,error?:string}
     */
    public static function enrollStudent(int $studentId, int $seriesId, array $opts = []): array
    {
        $tid    = current_tenant_id();
        $series = self::getClassSeries($seriesId);
        if ($series === null) {
            return ['ok' => false, 'id' => 0, 'status' => '', 'error' => 'unknown series'];
        }

        // Already actively enrolled? Return that row untouched.
        $existing = Database::row(
            "SELECT id, status FROM studio_enrollments
              WHERE tenant_id = ? AND student_id = ? AND series_id = ?
                AND status IN ('active', 'trial', 'waitlist')
              LIMIT 1",
            [$tid, $studentId, $seriesId]
        );
        if ($existing !== null) {
            return ['ok' => true, 'id' => (int) $existing['id'], 'status' => (string) $existing['status'], 'existing' => true];
        }

        // Capacity → waitlist unless overridden.
        $status = $opts['status'] ?? null;
        if ($status === null) {
            $activeCount = (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments
                  WHERE tenant_id = ? AND series_id = ? AND status IN ('active', 'trial')",
                [$tid, $seriesId]
            );
            $status = ($activeCount >= (int) $series['capacity']) ? 'waitlist' : 'active';
        }

        $id = Database::insert('studio_enrollments', [
            'tenant_id'   => $tid,
            'student_id'  => $studentId,
            'series_id'   => $seriesId,
            'enrolled_at' => date('Y-m-d'),
            'status'      => $status,
        ]);

        // Registration is charged on the family's first enrolment, not per
        // dancer and not per term — a second child joining does not pay it
        // again. raiseRegistrationFee is a no-op when registration is free
        // (the default) or already charged, so this is safe on every path.
        // A waitlisted place is NOT a sign-up, so it does not trigger it.
        if ($status !== 'waitlist') {
            $fam = Database::row(
                "SELECT family_id FROM studio_family_members
                  WHERE tenant_id = ? AND contact_id = ? LIMIT 1",
                [$tid, $studentId]
            );
            if ($fam) {
                try { self::raiseRegistrationFee((int) $fam['family_id']); }
                catch (\Throwable $e) {
                    // A billing hiccup must not block the enrolment itself —
                    // the place is the thing the parent came for.
                    slate_log('Studio: registration fee failed: ' . $e->getMessage(), 'error');
                }
            }
        }

        // Announced rather than emailed from here: StudioAPI stays a data layer
        // and the notifier attaches to the hook, same as the promotion path.
        if (class_exists('Hook')) {
            Hook::doAction('studio_enrollment_created', [
                'id'          => $id,
                'student_id'  => $studentId,
                'series_id'   => $seriesId,
                'series_name' => (string) $series['name'],
                'status'      => $status,
            ]);
        }

        return ['ok' => true, 'id' => $id, 'status' => $status];
    }

    /**
     * The billing parent behind a student — who a notification about that
     * student should actually reach. Students are children and generally have
     * no email of their own; the address lives on the family's primary parent.
     *
     * @return array{id:int,name:string,email:string}|null  null when the student
     *         is in no family, or the parent has no address on file.
     */
    public static function parentForStudent(int $studentId): ?array
    {
        $row = Database::row(
            "SELECT c.id, c.display_name, c.primary_email
               FROM studio_family_members m
               JOIN studio_families f ON f.id = m.family_id AND f.tenant_id = m.tenant_id
               JOIN contacts c ON c.id = f.primary_parent_id
              WHERE m.tenant_id = ? AND m.contact_id = ? AND m.relation = 'child'
           ORDER BY f.id ASC LIMIT 1",
            [current_tenant_id(), $studentId]
        );
        if ($row === null) { return null; }

        $email = trim((string) ($row['primary_email'] ?? ''));
        if ($email === '' || !str_contains($email, '@')) { return null; }

        return [
            'id'    => (int) $row['id'],
            'name'  => (string) ($row['display_name'] ?? ''),
            'email' => $email,
        ];
    }

    /**
     * Promote the longest-waiting student off a series' waitlist, if a seat is
     * free. Returns the promoted enrollment (id, student_id, student_name) or
     * null when the class is still full or nobody is waiting.
     *
     * Capacity is counted exactly as enrollStudent() counts it, so a seat that
     * would have produced a waitlist entry on the way in is the same seat that
     * releases one on the way out.
     *
     * The status is re-checked inside the UPDATE rather than trusted from the
     * SELECT: two drops processed at once would otherwise both read the same
     * head of the queue and promote it twice. Fires `studio_enrollment_promoted`
     * so notifications can attach without this method knowing about email.
     */
    public static function promoteFromWaitlist(int $seriesId): ?array
    {
        $tid    = current_tenant_id();
        $series = self::getClassSeries($seriesId);
        if ($series === null) { return null; }

        $activeCount = (int) Database::value(
            "SELECT COUNT(*) FROM studio_enrollments
              WHERE tenant_id = ? AND series_id = ? AND status IN ('active', 'trial')",
            [$tid, $seriesId]
        );
        if ($activeCount >= (int) $series['capacity']) { return null; }

        $next = Database::row(
            "SELECT e.id, e.student_id, c.display_name AS student_name
               FROM studio_enrollments e
               LEFT JOIN contacts c ON c.id = e.student_id
              WHERE e.tenant_id = ? AND e.series_id = ? AND e.status = 'waitlist'
           ORDER BY e.id ASC LIMIT 1",
            [$tid, $seriesId]
        );
        if ($next === null) { return null; }

        $affected = Database::query(
            "UPDATE studio_enrollments SET status = 'active'
              WHERE tenant_id = ? AND id = ? AND status = 'waitlist'",
            [$tid, (int) $next['id']]
        )->rowCount();
        if ($affected === 0) { return null; }   // someone else got there first

        $promoted = [
            'id'           => (int) $next['id'],
            'student_id'   => (int) $next['student_id'],
            'student_name' => (string) ($next['student_name'] ?? ''),
            'series_id'    => $seriesId,
            'series_name'  => (string) $series['name'],
        ];
        if (class_exists('Hook')) {
            Hook::doAction('studio_enrollment_promoted', $promoted);
        }
        return $promoted;
    }

    /**
     * Drop an enrollment: mark it 'dropped', stamp dropped_at, and record an
     * optional reason in meta. Tenant-scoped; returns false if not found.
     *
     * Dropping frees a seat, so the waitlist is promoted straight after —
     * previously a class could sit at capacity-minus-one with people waiting
     * until an admin noticed and moved someone by hand.
     */
    public static function dropEnrollment(int $enrollmentId, string $reason = ''): bool
    {
        $tid = current_tenant_id();
        $row = Database::row(
            'SELECT id, meta FROM studio_enrollments WHERE tenant_id = ? AND id = ?',
            [$tid, $enrollmentId]
        );
        if ($row === null) {
            return false;
        }

        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string) $row['meta'], true);
            if (is_array($decoded)) { $meta = $decoded; }
        }
        if ($reason !== '') { $meta['drop_reason'] = $reason; }

        $seriesId = (int) Database::value(
            'SELECT series_id FROM studio_enrollments WHERE tenant_id = ? AND id = ?',
            [$tid, $enrollmentId]
        );

        Database::update('studio_enrollments', [
            'status'     => 'dropped',
            'dropped_at' => date('Y-m-d'),
            'meta'       => $meta !== [] ? json_encode($meta) : null,
        ], 'tenant_id = ? AND id = ?', [$tid, $enrollmentId]);

        // A seat just opened. Never let this fail the drop itself.
        if ($seriesId > 0) {
            try { self::promoteFromWaitlist($seriesId); }
            catch (\Throwable $e) { slate_log('Studio: waitlist promotion failed: ' . $e->getMessage(), 'error'); }
        }

        return true;
    }

    /**
     * All enrollments (active/waitlist first, dropped last) with student name and
     * the series name/price/capacity — for the admin Enrollments list.
     *
     * @return array[]
     */
    public static function getEnrollments(): array
    {
        return Database::rows(
            "SELECT e.*, c.display_name AS student_name,
                    s.name AS series_name, s.price_cents, s.currency, s.capacity
               FROM studio_enrollments e
               JOIN studio_class_series s ON s.id = e.series_id AND s.tenant_id = e.tenant_id
               LEFT JOIN contacts c ON c.id = e.student_id
              WHERE e.tenant_id = ?
           ORDER BY (e.status = 'dropped'), e.id DESC",
            [current_tenant_id()]
        );
    }

    // ── Attendance ───────────────────────────────────────────────

    /** Occurrences (sessions) of a series, oldest first. */
    public static function getSeriesOccurrences(int $seriesId): array
    {
        return Database::rows(
            'SELECT * FROM studio_class_occurrences WHERE tenant_id = ? AND series_id = ? ORDER BY occurrence_date',
            [current_tenant_id(), $seriesId]
        );
    }

    /** A single occurrence row, or null. */
    public static function getOccurrence(int $id): ?array
    {
        return Database::row(
            'SELECT * FROM studio_class_occurrences WHERE tenant_id = ? AND id = ? LIMIT 1',
            [current_tenant_id(), $id]
        );
    }

    /** Enrolled roster for a series (active + trial), with student names. */
    public static function getRoster(int $seriesId): array
    {
        return Database::rows(
            "SELECT c.id, c.display_name, e.status
               FROM studio_enrollments e JOIN contacts c ON c.id = e.student_id
              WHERE e.tenant_id = ? AND e.series_id = ? AND e.status IN ('active','trial')
           ORDER BY c.display_name",
            [current_tenant_id(), $seriesId]
        );
    }

    /** Attendance for one occurrence as [student_id => status]. */
    public static function getAttendanceMap(int $occurrenceId): array
    {
        $map = [];
        foreach (Database::rows(
            'SELECT student_id, status FROM studio_attendance WHERE tenant_id = ? AND occurrence_id = ?',
            [current_tenant_id(), $occurrenceId]
        ) as $r) {
            $map[(int) $r['student_id']] = (string) $r['status'];
        }
        return $map;
    }

    /**
     * Upsert attendance for an occurrence. $studentStatuses = [studentId => status]
     * where status ∈ present|absent|excused|late. Idempotent per (occurrence, student).
     */
    public static function markAttendance(int $occurrenceId, array $studentStatuses, ?int $markedBy = null): void
    {
        $tid   = current_tenant_id();
        $valid = ['present', 'absent', 'excused', 'late'];
        foreach ($studentStatuses as $sid => $status) {
            $sid = (int) $sid;
            if ($sid <= 0) { continue; }
            if (!in_array($status, $valid, true)) { $status = 'present'; }
            Database::query(
                "INSERT INTO studio_attendance (tenant_id, occurrence_id, student_id, status, marked_by, marked_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by), marked_at = NOW()",
                [$tid, $occurrenceId, $sid, $status, $markedBy]
            );
        }
    }

    /** Occurrences of a series with a present/marked summary — for the session picker. */
    public static function getSeriesOccurrencesWithStats(int $seriesId): array
    {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM studio_attendance a WHERE a.tenant_id = o.tenant_id AND a.occurrence_id = o.id) AS marked_count,
                    (SELECT COUNT(*) FROM studio_attendance a WHERE a.tenant_id = o.tenant_id AND a.occurrence_id = o.id AND a.status = 'present') AS present_count
               FROM studio_class_occurrences o
              WHERE o.tenant_id = ? AND o.series_id = ?
           ORDER BY o.occurrence_date",
            [$tid, $seriesId]
        );
    }

    // ── Public catalog (customer-facing) ─────────────────────────

    /**
     * Active classes for the public catalog: instructor name, enrolled count and
     * open spots. Ordered by style then day/time.
     *
     * @return array[]
     */
    public static function getPublicCatalog(): array
    {
        $rows = Database::rows(
            "SELECT s.*, c.display_name AS instructor_name, c.primary_email AS instructor_email,
                    ir.meta AS instructor_meta,
                    (SELECT COUNT(*) FROM studio_enrollments e
                      WHERE e.tenant_id = s.tenant_id AND e.series_id = s.id
                        AND e.status IN ('active','trial')) AS enrolled_count
               FROM studio_class_series s
               LEFT JOIN contacts c ON c.id = s.instructor_id
               LEFT JOIN studio_contact_roles ir
                      ON ir.tenant_id = s.tenant_id AND ir.contact_id = s.instructor_id AND ir.role = 'instructor'
              WHERE s.tenant_id = ? AND s.is_active = 1
           ORDER BY s.day_of_week, s.start_time, s.name",
            [current_tenant_id()]
        );
        foreach ($rows as &$r) {
            $r['open_spots'] = max(0, (int) $r['capacity'] - (int) $r['enrolled_count']);
            $im = $r['instructor_meta'] ? (json_decode((string) $r['instructor_meta'], true) ?: []) : [];
            $r['instructor_image'] = (string) ($im['image'] ?? '');
        }
        unset($r);
        return $rows;
    }

    /**
     * Catalog templates a studio can choose between.
     *
     * The public catalog is one view with more than one right answer: a
     * day-picker keeps a long list short, while a full week grid puts every
     * class and price on one screen with nothing to tap. Which is better
     * depends on the studio, so it is a setting rather than a decision made
     * here.
     *
     * Adding a third is: drop a file in public/views/catalog/, add a row
     * here. The slug IS the filename, so nothing else needs to know.
     *
     * @return array<string, array{label: string, note: string}>
     */
    public static function catalogTemplates(): array
    {
        return [
            'list' => [
                'label' => __('studio_tpl_list', 'Day picker'),
                'note'  => __('studio_tpl_list_note',
                    'A week strip at the top; parents tap a day to see that day\'s classes. Best when a day holds a lot of classes.'),
            ],
            'grid' => [
                'label' => __('studio_tpl_grid', 'Weekly grid'),
                'note'  => __('studio_tpl_grid_note',
                    'The whole week as a timetable — every class, time and price on one page with nothing to tap. Stacks by time slot on a phone.'),
            ],
        ];
    }

    /** The chosen catalog template, falling back to the shipped default. */
    public static function catalogTemplate(): string
    {
        $slug = trim((string) Database::setting('studio.catalog_template'));
        return isset(self::catalogTemplates()[$slug]) ? $slug : 'list';
    }

    /**
     * The studio's published rate card: what a class costs, by type.
     *
     * Deliberately NOT derived from the catalog. It covers things that have
     * no class row — private vocal is sold by the half hour and never appears
     * as a series — and it is a published document, so it says "1.5 hour
     * ballet class" once rather than repeating a price against every ballet
     * class on the timetable.
     *
     * Stored as text and parsed, because the studio edits it as text. One
     * line per rate: "85 | 1 hour dance class".
     *
     * @return list<array{label: string, cents: int}>
     */
    public static function rateCard(): array
    {
        $raw = (string) Database::setting('studio.rate_card');
        if (trim($raw) === '') {
            $raw = self::DEFAULT_RATE_CARD;
        }
        return self::parseRateCard($raw);
    }

    /** The rate card as the studio types it, for the settings textarea. */
    public static function rateCardText(): string
    {
        $raw = (string) Database::setting('studio.rate_card');
        return trim($raw) === '' ? self::DEFAULT_RATE_CARD : $raw;
    }

    /** Round-tripped through the parser so a malformed line is dropped once, here. */
    public static function saveRateCard(string $text): void
    {
        $rows  = self::parseRateCard($text);
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = rtrim(rtrim(number_format($r['cents'] / 100, 2, '.', ''), '0'), '.')
                     . ' | ' . $r['label'];
        }
        Database::setSetting('studio.rate_card', implode("\n", $lines));
    }

    /** @return list<array{label: string, cents: int}> */
    private static function parseRateCard(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }

            // "85 | 1 hour dance class", tolerating a leading $ and stray spaces.
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) { continue; }

            $amount = trim(str_replace('$', '', $parts[0]));
            $label  = trim($parts[1]);
            if ($label === '' || !is_numeric($amount)) { continue; }

            $out[] = ['label' => $label, 'cents' => (int) round(((float) $amount) * 100)];
        }
        return $out;
    }

    /** Example rates shown until a studio enters its own. */
    private const DEFAULT_RATE_CARD = <<<'TXT'
    85 | 1 hour dance class
    128 | 1.5 hour ballet class
    140 | 1.5 hour Musical Theatre (dance and vocal training)
    70 | 1 hour private vocal
    45 | 1/2 hour private vocal
    100 | 1 hour vocal class
    TXT;

    // ── Seasons ───────────────────────────────────────────────────

    /** Seasons newest-first, with how many classes each holds. */
    public static function getSeasons(): array
    {
        return Database::rows(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM studio_class_series c
                      WHERE c.tenant_id = s.tenant_id AND c.season_id = s.id) AS class_count
               FROM studio_seasons s
              WHERE s.tenant_id = ?
           ORDER BY s.starts_on IS NULL, s.starts_on DESC, s.id DESC",
            [current_tenant_id()]
        );
    }

    public static function getSeason(int $id): ?array
    {
        return Database::row(
            "SELECT * FROM studio_seasons WHERE tenant_id = ? AND id = ?",
            [current_tenant_id(), $id]
        );
    }

    /** The season a new class should default to, or null. */
    public static function currentSeason(): ?array
    {
        return Database::row(
            "SELECT * FROM studio_seasons WHERE tenant_id = ? AND is_current = 1 LIMIT 1",
            [current_tenant_id()]
        );
    }

    public static function createSeason(array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException(__('studio_season_need_name', 'Give the season a name.'));
        }
        $id = Database::insert('studio_seasons', [
            'tenant_id'  => current_tenant_id(),
            'name'       => $name,
            'starts_on'  => ($d['starts_on'] ?? '') !== '' ? (string) $d['starts_on'] : null,
            'ends_on'    => ($d['ends_on']   ?? '') !== '' ? (string) $d['ends_on']   : null,
            'notes'      => trim((string) ($d['notes'] ?? '')) ?: null,
            'is_current' => 0,
            'created_at' => slate_db_now(),
            'updated_at' => slate_db_now(),
        ]);
        if (!empty($d['is_current'])) { self::setCurrentSeason($id); }
        return $id;
    }

    public static function updateSeason(int $id, array $d): bool
    {
        $patch = ['updated_at' => slate_db_now()];
        foreach (['name', 'notes'] as $k) {
            if (array_key_exists($k, $d)) { $patch[$k] = trim((string) $d[$k]) ?: null; }
        }
        foreach (['starts_on', 'ends_on'] as $k) {
            if (array_key_exists($k, $d)) { $patch[$k] = ($d[$k] ?? '') !== '' ? (string) $d[$k] : null; }
        }
        if (isset($patch['name']) && $patch['name'] === null) {
            throw new \InvalidArgumentException(__('studio_season_need_name', 'Give the season a name.'));
        }
        $n = Database::update('studio_seasons', $patch, 'tenant_id = ? AND id = ?', [current_tenant_id(), $id]);
        if (!empty($d['is_current'])) { self::setCurrentSeason($id); }
        return $n >= 0;
    }

    /**
     * Make one season current, clearing the rest.
     *
     * Two statements rather than a unique index: the natural operation is
     * "make this one current", and a unique index would turn that into a
     * delete-then-insert dance for no benefit.
     */
    public static function setCurrentSeason(int $id): void
    {
        $tid = current_tenant_id();
        Database::update('studio_seasons', ['is_current' => 0], 'tenant_id = ?', [$tid]);
        Database::update('studio_seasons', ['is_current' => 1, 'updated_at' => slate_db_now()],
            'tenant_id = ? AND id = ?', [$tid, $id]);
    }

    /**
     * Delete a season. Its classes are UNASSIGNED, never deleted — a season is
     * a grouping, and removing the folder must not remove what was in it.
     */
    public static function deleteSeason(int $id): bool
    {
        $tid = current_tenant_id();
        Database::update('studio_class_series', ['season_id' => null],
            'tenant_id = ? AND season_id = ?', [$tid, $id]);
        return Database::delete('studio_seasons', 'tenant_id = ? AND id = ?', [$tid, $id]) > 0;
    }

    /** Classes in a season, active and inactive. */
    public static function seasonClasses(int $seasonId): array
    {
        return Database::rows(
            "SELECT * FROM studio_class_series
              WHERE tenant_id = ? AND season_id = ?
           ORDER BY day_of_week, start_time",
            [current_tenant_id(), $seasonId]
        );
    }

    /**
     * What a duplication would do, without doing it.
     *
     * Returns the shift in whole weeks, how far that lands from the requested
     * start, and the per-class before/after. The drift matters: an owner who
     * types a Wednesday when the term ran on Mondays needs telling that their
     * Monday classes stay on Monday, rather than discovering it later.
     */
    public static function previewSeasonClone(int $seasonId, string $newStart): array
    {
        $season = self::getSeason($seasonId);
        if ($season === null) {
            throw new \InvalidArgumentException(__('studio_season_gone', 'That season no longer exists.'));
        }
        $oldStart = (string) ($season['starts_on'] ?? '');
        if ($oldStart === '') {
            throw new \InvalidArgumentException(
                __('studio_season_no_start', 'Give the season a start date before duplicating it.'));
        }

        $weeks = SeasonShift::weeksBetween($oldStart, $newStart);
        if ($weeks === null) {
            throw new \InvalidArgumentException(__('studio_season_bad_date', 'That start date is not a real date.'));
        }

        $classes = self::seasonClasses($seasonId);
        return [
            'season'  => $season,
            'weeks'   => $weeks,
            'drift'   => SeasonShift::driftDays($oldStart, $newStart) ?? 0,
            'name'    => SeasonShift::copyName((string) $season['name']),
            'classes' => SeasonShift::plan($classes, $weeks),
        ];
    }

    /**
     * Duplicate a season and everything in it.
     *
     * Enrolments are deliberately NOT copied. A new term is a new commitment —
     * carrying last term's roster forward would silently bill families for
     * classes nobody signed up to, which is the single most damaging thing
     * this feature could do. Occurrences ARE generated, because a term with no
     * lessons on the calendar is not usable.
     *
     * @return array{season_id:int,classes:int,occurrences:int,failed:int}
     */
    public static function cloneSeason(int $seasonId, string $newStart, ?string $newName = null): array
    {
        $plan = self::previewSeasonClone($seasonId, $newStart);
        $tid  = current_tenant_id();

        $span = null;
        if (($plan['season']['starts_on'] ?? null) && ($plan['season']['ends_on'] ?? null)) {
            $span = SeasonShift::shift((string) $plan['season']['ends_on'], $plan['weeks']);
        }

        $newSeasonId = self::createSeason([
            'name'      => trim((string) ($newName ?? '')) !== '' ? $newName : $plan['name'],
            'starts_on' => SeasonShift::shift((string) $plan['season']['starts_on'], $plan['weeks']),
            'ends_on'   => $span,
        ]);

        $made = 0; $occ = 0; $failed = 0;
        foreach ($plan['classes'] as $c) {
            if (!empty($c['_shift_failed'])) { $failed++; continue; }

            // Carry EVERY descriptive field, not just the image — a duplicated
            // class that lost its description and location would need retyping,
            // which is the work this feature exists to remove.
            $carry = [];
            foreach (self::CLASS_META_KEYS as $k) { $carry[$k] = self::classMeta($c, $k); }

            $newId = self::createClassSeries($carry + [
                'name'          => (string) $c['name'],
                'style'         => (string) $c['style'],
                'level'         => $c['level'] ?? null,
                'age_min'       => (int) ($c['age_min'] ?? 0),
                'age_max'       => (int) ($c['age_max'] ?? 99),
                'capacity'      => (int) ($c['capacity'] ?? 20),
                'instructor_id' => (int) $c['instructor_id'],
                'room_id'       => $c['room_id'] !== null ? (int) $c['room_id'] : null,
                'day_of_week'   => (int) $c['day_of_week'],
                'start_time'    => (string) $c['start_time'],
                'end_time'      => (string) $c['end_time'],
                'session_start' => (string) $c['session_start'],
                'session_end'   => (string) $c['session_end'],
                'price_cents'   => (int) $c['price_cents'],
                'currency'      => (string) ($c['currency'] ?? 'USD'),
                'is_active'     => (int) ($c['is_active'] ?? 1),
            ]);
            Database::update('studio_class_series', ['season_id' => $newSeasonId],
                'tenant_id = ? AND id = ?', [$tid, $newId]);

            $occ += self::generateOccurrences($newId);
            $made++;
        }

        return ['season_id' => $newSeasonId, 'classes' => $made, 'occurrences' => $occ, 'failed' => $failed];
    }

    /** Duplicate one class within its own season, for a second section. */
    public static function duplicateClass(int $seriesId, array $overrides = []): int
    {
        $c = self::getClassSeries($seriesId);
        if ($c === null) {
            throw new \InvalidArgumentException(__('studio_class_gone', 'That class no longer exists.'));
        }
        // Every descriptive field carries over, not just the image.
        $carry = [];
        foreach (self::CLASS_META_KEYS as $k) { $carry[$k] = self::classMeta($c, $k); }

        $data = $carry + [
            'name'          => SeasonShift::copyName((string) $c['name']),
            'style'         => (string) $c['style'],
            'level'         => $c['level'] ?? null,
            'age_min'       => (int) ($c['age_min'] ?? 0),
            'age_max'       => (int) ($c['age_max'] ?? 99),
            'capacity'      => (int) ($c['capacity'] ?? 20),
            'instructor_id' => (int) $c['instructor_id'],
            'room_id'       => $c['room_id'] !== null ? (int) $c['room_id'] : null,
            'day_of_week'   => (int) $c['day_of_week'],
            'start_time'    => (string) $c['start_time'],
            'end_time'      => (string) $c['end_time'],
            'session_start' => (string) $c['session_start'],
            'session_end'   => (string) $c['session_end'],
            'price_cents'   => (int) $c['price_cents'],
            'currency'      => (string) ($c['currency'] ?? 'USD'),
        ] + $overrides;

        $newId = self::createClassSeries($data);
        if ($c['season_id'] !== null) {
            Database::update('studio_class_series', ['season_id' => (int) $c['season_id']],
                'tenant_id = ? AND id = ?', [current_tenant_id(), $newId]);
        }
        self::generateOccurrences($newId);
        return $newId;
    }

    // ── Closures: holidays and cancelled lessons ──────────────────

    /**
     * Per-tenant memo for the closure list. A class property rather than a
     * `static` inside the method so saveHolidays() can invalidate it — the
     * common sequence is "save the winter break, then regenerate the term",
     * and a stale memo there silently generates lessons on the closed dates.
     *
     * @var array<int,HolidayCalendar>
     */
    private static array $holidayCache = [];

    /**
     * Every settings-derived policy object is memoised per tenant in a CLASS
     * property, never a `static` inside the getter, because each has a setter
     * — and a setter that leaves a stale memo behind is a bug that only shows
     * when something saves and reads back in the same request. See
     * $holidayCache / $feeCache / $planCache / $discountCache / $reminderCache,
     * each cleared by its own save* method.
     */
    private static array $discountCache = [];

    private static array $reminderCache = [];

    /** The tenant's closure dates. Memoised — generation asks per date. */
    public static function holidayCalendar(): HolidayCalendar
    {
        $tid = current_tenant_id();
        if (isset(self::$holidayCache[$tid])) { return self::$holidayCache[$tid]; }
        return self::$holidayCache[$tid] = HolidayCalendar::fromText(
            (string) Database::setting('studio.holidays')
        );
    }

    /** Persist the closure list. Round-tripped so a bad line is dropped once. */
    public static function saveHolidays(string $text): void
    {
        $cal   = HolidayCalendar::fromText($text);
        $lines = [];
        foreach ($cal->all() as $date => $label) {
            $lines[] = $label !== '' ? "{$date} {$label}" : $date;
        }
        Database::setSetting('studio.holidays', implode("\n", $lines));

        // Must come after the write, and must not simply store $cal — a second
        // tenant in the same process has its own list.
        unset(self::$holidayCache[current_tenant_id()]);
    }

    /**
     * Cancel one lesson. Attendance already taken is left alone — it is a
     * record of who actually turned up, and a later cancellation does not
     * change history.
     */
    public static function cancelOccurrence(int $occurrenceId, string $note = ''): bool
    {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT id, status FROM studio_class_occurrences WHERE tenant_id = ? AND id = ?",
            [$tid, $occurrenceId]
        );
        if (!$row || $row['status'] === 'cancelled') { return false; }

        Database::update('studio_class_occurrences', [
            'status'       => 'cancelled',
            'note'         => trim($note) !== '' ? mb_substr(trim($note), 0, 190) : null,
            'cancelled_at' => slate_db_now(),
        ], 'tenant_id = ? AND id = ?', [$tid, $occurrenceId]);

        if (class_exists('Hook')) {
            Hook::doAction('studio_lesson_cancelled', $occurrenceId, $note);
        }
        return true;
    }

    /** Put a cancelled lesson back on. */
    public static function restoreOccurrence(int $occurrenceId): bool
    {
        $tid = current_tenant_id();
        $n = Database::update('studio_class_occurrences', [
            'status'       => 'scheduled',
            'note'         => null,
            'cancelled_at' => null,
        ], "tenant_id = ? AND id = ? AND status = 'cancelled'", [$tid, $occurrenceId]);
        return $n > 0;
    }

    /**
     * Cancel any already-generated lesson that now falls on a closure date.
     *
     * Needed because holidays are usually added AFTER a season is generated —
     * an owner sets the term up in August and adds the winter break in
     * November. Returns how many were cancelled.
     */
    public static function applyHolidaysToExisting(): int
    {
        $tid  = current_tenant_id();
        $cal  = self::holidayCalendar();
        if ($cal->count() === 0) { return 0; }

        $dates = array_keys($cal->all());
        $ph    = implode(',', array_fill(0, count($dates), '?'));
        $rows  = Database::rows(
            "SELECT id, occurrence_date FROM studio_class_occurrences
              WHERE tenant_id = ? AND status = 'scheduled' AND occurrence_date IN ($ph)",
            array_merge([$tid], $dates)
        );

        $n = 0;
        foreach ($rows as $r) {
            $label = $cal->labelFor((string) $r['occurrence_date']);
            if (self::cancelOccurrence((int) $r['id'],
                    $label !== '' ? $label : __('studio_closed', 'Studio closed'))) { $n++; }
        }
        return $n;
    }

    /**
     * A family's lessons over a window, for the parent agenda.
     *
     * Includes cancelled ones deliberately: a parent needs to see that
     * Tuesday is off, and hiding it looks identical to the class never having
     * existed.
     */
    public static function upcomingLessonsForParent(int $parentContactId, int $days = 7): array
    {
        $tid = current_tenant_id();
        $fam = self::getFamilyByParent($parentContactId);
        if ($fam === null) { return []; }

        $ids = self::familyStudentIds((int) $fam['id']);
        if (!$ids) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        return Database::rows(
            "SELECT o.id, o.occurrence_date, o.start_time, o.end_time, o.status, o.note,
                    s.name AS series_name, s.room_id,
                    c.display_name AS instructor_name,
                    st.display_name AS student_name
               FROM studio_class_occurrences o
               JOIN studio_class_series s ON s.id = o.series_id AND s.tenant_id = o.tenant_id
               JOIN studio_enrollments e  ON e.series_id = s.id AND e.tenant_id = s.tenant_id
                                         AND e.status IN ('active','trial')
               JOIN contacts st           ON st.id = e.student_id
               LEFT JOIN contacts c       ON c.id = s.instructor_id
              WHERE o.tenant_id = ? AND e.student_id IN ($ph)
                AND o.occurrence_date >= CURDATE()
                AND o.occurrence_date < DATE_ADD(CURDATE(), INTERVAL ? DAY)
           ORDER BY o.occurrence_date, o.start_time",
            array_merge([$tid], $ids, [max(1, $days)])
        );
    }

    /** One active class for the public detail page (with instructor + counts), or null. */
    public static function getPublicClass(int $seriesId): ?array
    {
        $r = Database::row(
            "SELECT s.*, c.display_name AS instructor_name, c.primary_email AS instructor_email,
                    ir.meta AS instructor_meta,
                    (SELECT COUNT(*) FROM studio_enrollments e
                      WHERE e.tenant_id = s.tenant_id AND e.series_id = s.id
                        AND e.status IN ('active','trial')) AS enrolled_count
               FROM studio_class_series s
               LEFT JOIN contacts c ON c.id = s.instructor_id
               LEFT JOIN studio_contact_roles ir
                      ON ir.tenant_id = s.tenant_id AND ir.contact_id = s.instructor_id AND ir.role = 'instructor'
              WHERE s.tenant_id = ? AND s.id = ? AND s.is_active = 1
              LIMIT 1",
            [current_tenant_id(), $seriesId]
        );
        if ($r === null) { return null; }
        $r['open_spots'] = max(0, (int) $r['capacity'] - (int) $r['enrolled_count']);
        $im = $r['instructor_meta'] ? (json_decode((string) $r['instructor_meta'], true) ?: []) : [];
        $r['instructor_image'] = (string) ($im['image'] ?? '');
        return $r;
    }

    // ── Parent portal (customer-facing, login-gated) ─────────────

    /** Get the parent's family id, creating an empty family if none exists. */
    public static function ensureFamilyForParent(int $contactId): int
    {
        $fam = self::getFamilyByParent($contactId);
        return $fam ? (int) $fam['id'] : self::createFamily($contactId, []);
    }

    /**
     * Portal bundle for a logged-in parent: their family, students, and each
     * student's non-dropped enrollments (with schedule + applied tuition).
     *
     * @return array{family:?array,students:array[]}
     */
    public static function getParentPortal(int $contactId): array
    {
        $tid = current_tenant_id();
        $fam = self::getFamilyByParent($contactId);
        $students = [];
        if ($fam) {
            foreach (self::familyStudentIds((int) $fam['id']) as $sid) {
                $name = (string) Database::value('SELECT display_name FROM contacts WHERE id = ?', [$sid]);
                $enr  = Database::rows(
                    "SELECT e.id, e.status, e.series_id, e.meta, s.name AS series_name,
                            s.day_of_week, s.start_time, s.end_time, s.price_cents
                       FROM studio_enrollments e
                       JOIN studio_class_series s ON s.id = e.series_id
                      WHERE e.tenant_id = ? AND e.student_id = ? AND e.status <> 'dropped'
                   ORDER BY e.id DESC",
                    [$tid, $sid]
                );
                foreach ($enr as &$en) {
                    try { $en['tuition_cents'] = self::calculateTuition($sid, (int) $en['series_id'])->minor; }
                    catch (\Throwable $e) { $en['tuition_cents'] = (int) $en['price_cents']; }
                    $pay = self::enrollmentPaidInfo($en['meta'] ?? null);
                    $en['paid']       = $pay['paid'];
                    $en['paid_cents'] = $pay['paid_cents'];
                }
                unset($en);
                $students[] = ['id' => $sid, 'name' => $name, 'enrollments' => $enr];
            }
        }
        return ['family' => $fam, 'students' => $students];
    }

    // ── Billing (Stripe via the stripe-payment shared ledger) ────

    /** Decode the paid state stored in an enrollment's meta JSON. */
    public static function enrollmentPaidInfo(?string $metaJson): array
    {
        $m = $metaJson ? (json_decode((string) $metaJson, true) ?: []) : [];
        return [
            'paid'       => !empty($m['paid']),
            'paid_cents' => (int) ($m['paid_cents'] ?? 0),
            'charge_id'  => (int) ($m['charge_id'] ?? 0),
        ];
    }

    /** True if the enrollment's student belongs to this parent's family (payment guard). */
    public static function enrollmentBelongsToParent(int $enrollmentId, int $parentContactId): bool
    {
        $fam = self::getFamilyByParent($parentContactId);
        if ($fam === null) { return false; }
        $kids = self::familyStudentIds((int) $fam['id']);
        if ($kids === []) { return false; }
        $sid = (int) Database::value(
            'SELECT student_id FROM studio_enrollments WHERE tenant_id = ? AND id = ?',
            [current_tenant_id(), $enrollmentId]
        );
        return in_array($sid, $kids, true);
    }

    /**
     * Mark an enrollment paid: record the charge in the shared ledger (idempotent)
     * and stamp paid state onto the enrollment meta. Called from the Stripe webhook.
     */
    public static function markEnrollmentPaid(int $enrollmentId, int $amountCents, string $sessionId = '', string $paymentIntent = '', string $email = ''): void
    {
        $tid = current_tenant_id();
        $row = Database::row('SELECT id, meta FROM studio_enrollments WHERE tenant_id = ? AND id = ?', [$tid, $enrollmentId]);
        if ($row === null) { return; }

        $chargeId = null;
        if (class_exists('StripePaymentAPI')) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'studio',
                'source_id'                => (string) $enrollmentId,
                'stripe_session_id'        => $sessionId,
                'stripe_payment_intent_id' => $paymentIntent,
                'customer_email'           => $email,
                'amount_cents'             => $amountCents,
                'currency'                 => 'USD',
                'status'                   => 'succeeded',
            ]);
        }

        $meta = $row['meta'] ? (json_decode((string) $row['meta'], true) ?: []) : [];
        $wasPaid = !empty($meta['paid']);

        $meta['paid']       = true;
        $meta['paid_cents'] = $amountCents;
        $meta['paid_at']    = slate_db_now();
        if ($chargeId)          { $meta['charge_id'] = (int) $chargeId; }
        if ($sessionId !== '')  { $meta['stripe_session'] = $sessionId; }
        Database::update('studio_enrollments', ['meta' => json_encode($meta)], 'tenant_id = ? AND id = ?', [$tid, $enrollmentId]);

        // Only on the transition to paid. Both the checkout return and the
        // webhook call this for the same payment, and whichever arrives second
        // must not send the parent a second receipt.
        if (!$wasPaid && class_exists('Hook')) {
            Hook::doAction('studio_enrollment_paid', [
                'id'           => $enrollmentId,
                'amount_cents' => $amountCents,
                'method'       => $sessionId !== '' ? 'online' : 'offline',
            ]);
        }
    }

    /** Record an offline (cash/front-desk) tuition payment: stamps paid meta, no Stripe charge. */
    public static function markEnrollmentPaidOffline(int $enrollmentId, int $amountCents): bool
    {
        $tid = current_tenant_id();
        $row = Database::row('SELECT meta FROM studio_enrollments WHERE tenant_id = ? AND id = ?', [$tid, $enrollmentId]);
        if ($row === null) { return false; }
        $meta = $row['meta'] ? (json_decode((string) $row['meta'], true) ?: []) : [];
        $meta['paid']        = true;
        $meta['paid_cents']  = $amountCents;
        $meta['paid_at']     = slate_db_now();
        $meta['paid_method'] = 'offline';
        Database::update('studio_enrollments', ['meta' => json_encode($meta)], 'tenant_id = ? AND id = ?', [$tid, $enrollmentId]);
        return true;
    }

    // ── Tuition (pure delegation to the domain calculator) ───────

    /**
     * Tuition for a student on a series, applying the studio's multi-class +
     * sibling discount policy. Reads live enrollment counts to size the discount.
     */
    public static function calculateTuition(int $studentId, int $seriesId): Money
    {
        $tid    = current_tenant_id();
        $series = self::getClassSeries($seriesId);
        if ($series === null) {
            throw new \InvalidArgumentException("calculateTuition: unknown series {$seriesId}");
        }

        $activeForStudent = (int) Database::value(
            "SELECT COUNT(*) FROM studio_enrollments
              WHERE tenant_id = ? AND student_id = ? AND status IN ('active', 'trial')",
            [$tid, $studentId]
        );
        $activeForStudent = max(1, $activeForStudent);

        // Siblings = other children in this student's family (if any).
        $siblingCount = (int) Database::value(
            "SELECT COUNT(DISTINCT fm2.contact_id)
               FROM studio_family_members fm1
               JOIN studio_family_members fm2
                 ON fm2.family_id = fm1.family_id
                AND fm2.contact_id <> fm1.contact_id
              WHERE fm1.tenant_id = ? AND fm1.contact_id = ? AND fm1.relation = 'child'",
            [$tid, $studentId]
        );

        return (new TuitionCalculator())->calculate($series, $activeForStudent, $siblingCount, self::discountPolicy());
    }

    /**
     * The tenant's discount curves, or the shipped defaults when unset.
     * Memoised per request: tuition is recalculated for every row on the
     * Enrollments list, and this would otherwise be two settings reads each.
     */
    public static function discountPolicy(): DiscountPolicy
    {
        $tid = current_tenant_id();
        if (isset(self::$discountCache[$tid])) { return self::$discountCache[$tid]; }

        return self::$discountCache[$tid] = DiscountPolicy::fromJson(
            (string) Database::setting('studio.discount_multi'),
            (string) Database::setting('studio.discount_sibling')
        );
    }

    // ── Recitals ──────────────────────────────────────────────

    /** Recitals newest-first, with piece and ticket counts for the list. */
    public static function getRecitals(): array
    {
        return Database::rows(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM studio_recital_pieces p
                      WHERE p.tenant_id = r.tenant_id AND p.recital_id = r.id) AS piece_count,
                    (SELECT COALESCE(SUM(t.quantity),0) FROM studio_recital_tickets t
                      WHERE t.tenant_id = r.tenant_id AND t.recital_id = r.id AND t.status IN ('reserved','paid')) AS tickets_out,
                    (SELECT COALESCE(SUM(t.amount_cents),0) FROM studio_recital_tickets t
                      WHERE t.tenant_id = r.tenant_id AND t.recital_id = r.id AND t.status = 'paid') AS ticket_revenue
               FROM studio_recitals r
              WHERE r.tenant_id = ?
           ORDER BY COALESCE(r.recital_date, '9999-12-31') DESC, r.id DESC",
            [current_tenant_id()]
        );
    }

    public static function getRecital(int $id): ?array
    {
        return Database::row('SELECT * FROM studio_recitals WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $id]);
    }

    public static function createRecital(array $d): int
    {
        return Database::insert('studio_recitals', self::recitalFields($d) + ['tenant_id' => current_tenant_id()]);
    }

    public static function updateRecital(int $id, array $d): bool
    {
        $f = self::recitalFields($d);
        $f['updated_at'] = slate_db_now();
        return Database::update('studio_recitals', $f, 'tenant_id = ? AND id = ?', [current_tenant_id(), $id]) >= 0;
    }

    /** Whitelist + normalise, so a stray POST key can't reach the table. */
    private static function recitalFields(array $d): array
    {
        $money = static fn ($v): int => (int) round(((float) $v) * 100);
        $out = [
            'name'               => trim((string) ($d['name'] ?? '')),
            'recital_date'       => ($d['recital_date'] ?? '') !== '' ? (string) $d['recital_date'] : null,
            'venue'              => trim((string) ($d['venue'] ?? '')) ?: null,
            'call_time'          => trim((string) ($d['call_time'] ?? '')) ?: null,
            'doors_time'         => trim((string) ($d['doors_time'] ?? '')) ?: null,
            'ticket_price_cents' => isset($d['ticket_price_cents']) ? (int) $d['ticket_price_cents'] : $money($d['ticket_price'] ?? 0),
            'seats_total'        => ($d['seats_total'] ?? '') !== '' ? (int) $d['seats_total'] : null,
            'notes'              => trim((string) ($d['notes'] ?? '')) ?: null,
        ];
        $status = (string) ($d['status'] ?? 'draft');
        $out['status'] = in_array($status, ['draft', 'published', 'cancelled', 'done'], true) ? $status : 'draft';
        if ($out['name'] === '') { throw new \InvalidArgumentException(__('studio_recital_need_name', 'Give the recital a name.')); }
        return $out;
    }

    public static function deleteRecital(int $id): bool
    {
        $tid = current_tenant_id();
        // Costumes hang off pieces, so clear them before the pieces disappear.
        $pieceIds = array_column(Database::rows(
            'SELECT id FROM studio_recital_pieces WHERE tenant_id = ? AND recital_id = ?', [$tid, $id]), 'id');
        foreach ($pieceIds as $pid) {
            Database::delete('studio_costumes', 'tenant_id = ? AND piece_id = ?', [$tid, (int) $pid]);
        }
        Database::delete('studio_recital_pieces',  'tenant_id = ? AND recital_id = ?', [$tid, $id]);
        Database::delete('studio_recital_tickets', 'tenant_id = ? AND recital_id = ?', [$tid, $id]);
        return Database::delete('studio_recitals', 'tenant_id = ? AND id = ?', [$tid, $id]) > 0;
    }

    /** The running order, with the class name and how many costumes are outstanding. */
    public static function getRecitalPieces(int $recitalId): array
    {
        return Database::rows(
            "SELECT p.*, s.name AS class_name, s.capacity,
                    (SELECT COUNT(*) FROM studio_costumes c
                      WHERE c.tenant_id = p.tenant_id AND c.piece_id = p.id) AS costume_count,
                    (SELECT COUNT(*) FROM studio_costumes c
                      WHERE c.tenant_id = p.tenant_id AND c.piece_id = p.id AND c.paid = 1) AS costume_paid
               FROM studio_recital_pieces p
               JOIN studio_class_series s ON s.id = p.series_id AND s.tenant_id = p.tenant_id
              WHERE p.tenant_id = ? AND p.recital_id = ?
           ORDER BY p.position ASC, p.id ASC",
            [current_tenant_id(), $recitalId]
        );
    }

    /**
     * Put a class in a recital. Idempotent — the unique index means adding the
     * same class twice is a no-op rather than an error the admin has to read.
     */
    public static function addRecitalPiece(int $recitalId, int $seriesId, array $d = []): int
    {
        $tid = current_tenant_id();
        $existing = (int) Database::value(
            'SELECT id FROM studio_recital_pieces WHERE tenant_id = ? AND recital_id = ? AND series_id = ?',
            [$tid, $recitalId, $seriesId]
        );
        if ($existing > 0) { return $existing; }

        $next = (int) Database::value(
            'SELECT COALESCE(MAX(position),0)+1 FROM studio_recital_pieces WHERE tenant_id = ? AND recital_id = ?',
            [$tid, $recitalId]
        );
        return Database::insert('studio_recital_pieces', [
            'tenant_id'  => $tid,
            'recital_id' => $recitalId,
            'series_id'  => $seriesId,
            'title'      => trim((string) ($d['title'] ?? '')) ?: null,
            'music'      => trim((string) ($d['music'] ?? '')) ?: null,
            'position'   => $next,
        ]);
    }

    public static function removeRecitalPiece(int $pieceId): bool
    {
        $tid = current_tenant_id();
        Database::delete('studio_costumes', 'tenant_id = ? AND piece_id = ?', [$tid, $pieceId]);
        return Database::delete('studio_recital_pieces', 'tenant_id = ? AND id = ?', [$tid, $pieceId]) > 0;
    }

    /**
     * Create a costume row for every dancer currently in the piece's class, at
     * the given cost. Existing rows are left alone — re-running after a new
     * enrolment adds only the newcomer, so an admin can press it again safely
     * without resetting sizes already collected.
     *
     * @return int how many were created
     */
    /**
     * Create a wardrobe record per dancer in a piece — sizing and fulfilment
     * only. It does NOT price anything.
     *
     * This used to write cost_cents, which meant a costume was billed twice:
     * once here and once in studio_fees, where generateCostumeFeesForStudent()
     * raises it against the enrolment with the Nov/Feb instalment split. The
     * parent portal showed both, in two different cards, for the same $95.
     *
     * The split now is: studio_costumes answers "what size, ordered yet,
     * handed over yet"; studio_fees answers "what is owed". One place for
     * money. $costCents is accepted and ignored so existing callers keep
     * working — see the deprecation note on the parameter.
     *
     * @param int $costCents Deprecated and ignored. Costume pricing lives in
     *                       FeeSchedule::costumeCents(); passing a value here
     *                       would reintroduce the double-bill.
     */
    public static function syncCostumesForPiece(int $pieceId, int $costCents = 0): int
    {
        $tid   = current_tenant_id();
        $piece = Database::row('SELECT id, series_id FROM studio_recital_pieces WHERE tenant_id = ? AND id = ?', [$tid, $pieceId]);
        if ($piece === null) { return 0; }

        $students = array_column(Database::rows(
            "SELECT student_id FROM studio_enrollments
              WHERE tenant_id = ? AND series_id = ? AND status IN ('active','trial')",
            [$tid, (int) $piece['series_id']]
        ), 'student_id');

        $made = 0;
        foreach ($students as $sid) {
            $has = (int) Database::value(
                'SELECT id FROM studio_costumes WHERE tenant_id = ? AND piece_id = ? AND student_id = ?',
                [$tid, $pieceId, (int) $sid]
            );
            if ($has > 0) { continue; }
            Database::insert('studio_costumes', [
                'tenant_id'  => $tid,
                'piece_id'   => $pieceId,
                'student_id' => (int) $sid,
                // cost_cents stays 0 on purpose. The money for this costume is
                // raised in studio_fees against the enrolment, not here.
                'cost_cents' => 0,
            ]);
            $made++;
        }
        return $made;
    }

    /** Costume rows for a piece, with the dancer's name. */
    public static function getCostumesForPiece(int $pieceId): array
    {
        return Database::rows(
            "SELECT c.*, ct.display_name AS student_name
               FROM studio_costumes c
               LEFT JOIN contacts ct ON ct.id = c.student_id
              WHERE c.tenant_id = ? AND c.piece_id = ?
           ORDER BY ct.display_name ASC, c.id ASC",
            [current_tenant_id(), $pieceId]
        );
    }

    public static function updateCostume(int $id, array $d): bool
    {
        $tid = current_tenant_id();
        $f = ['updated_at' => slate_db_now()];
        if (array_key_exists('size', $d))       { $f['size'] = trim((string) $d['size']) ?: null; }
        if (array_key_exists('notes', $d))      { $f['notes'] = trim((string) $d['notes']) ?: null; }
        if (array_key_exists('cost_cents', $d)) { $f['cost_cents'] = max(0, (int) $d['cost_cents']); }
        if (array_key_exists('status', $d)) {
            $s = (string) $d['status'];
            if (in_array($s, ['pending', 'measured', 'ordered', 'received', 'distributed'], true)) { $f['status'] = $s; }
        }
        if (array_key_exists('paid', $d)) {
            $f['paid'] = !empty($d['paid']) ? 1 : 0;
            $f['paid_at'] = !empty($d['paid']) ? slate_db_now() : null;
        }
        return Database::update('studio_costumes', $f, 'tenant_id = ? AND id = ?', [$tid, $id]) >= 0;
    }

    /**
     * Ticket + costume money for one recital, for the admin summary.
     *
     * @return array{tickets_sold:int,tickets_paid:int,ticket_revenue_cents:int,
     *               seats_total:?int,costumes:int,costumes_paid:int,costume_due_cents:int}
     */
    public static function recitalSummary(int $recitalId): array
    {
        $tid = current_tenant_id();
        $r   = self::getRecital($recitalId);

        $t = Database::row(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('reserved','paid') THEN quantity END),0) AS out_qty,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN quantity END),0)               AS paid_qty,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_cents END),0)           AS revenue
               FROM studio_recital_tickets WHERE tenant_id = ? AND recital_id = ?",
            [$tid, $recitalId]
        ) ?: [];

        $c = Database::row(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(c.paid),0) AS paid_n,
                    -- Always 0 now: studio_costumes no longer prices anything.
                    -- Kept so the shape of the summary is unchanged for
                    -- callers; the real figure is read from studio_fees below.
                    0 AS due
               FROM studio_costumes c
               JOIN studio_recital_pieces p ON p.id = c.piece_id AND p.tenant_id = c.tenant_id
              WHERE c.tenant_id = ? AND p.recital_id = ?",
            [$tid, $recitalId]
        ) ?: [];

        return [
            'tickets_sold'         => (int) ($t['out_qty'] ?? 0),
            'tickets_paid'         => (int) ($t['paid_qty'] ?? 0),
            'ticket_revenue_cents' => (int) ($t['revenue'] ?? 0),
            'seats_total'          => isset($r['seats_total']) && $r['seats_total'] !== null ? (int) $r['seats_total'] : null,
            'costumes'             => (int) ($c['n'] ?? 0),
            'costumes_paid'        => (int) ($c['paid_n'] ?? 0),
            // Read from the fee ledger, scoped to the families whose dancers
            // are actually in this show. studio_costumes no longer holds money.
            'costume_due_cents'    => (int) Database::value(
                "SELECT COALESCE(SUM(fe.amount_cents),0)
                   FROM studio_fees fe
                  WHERE fe.tenant_id = ? AND fe.status = 'pending'
                    AND fe.kind IN ('costume','tights')
                    AND fe.family_id IN (
                        SELECT DISTINCT fm.family_id
                          FROM studio_costumes co
                          JOIN studio_recital_pieces p
                            ON p.id = co.piece_id AND p.tenant_id = co.tenant_id
                          JOIN studio_family_members fm
                            ON fm.contact_id = co.student_id AND fm.tenant_id = co.tenant_id
                         WHERE co.tenant_id = ? AND p.recital_id = ?)",
                [$tid, $tid, $recitalId]
            ),
        ];
    }

    /**
     * Published recitals a parent's dancers are actually in, with what that
     * family owes and holds. Draft and cancelled shows are invisible to
     * parents — an admin sketching next season shouldn't leak it.
     *
     * @return array[] recital rows + performers[], costume_due_cents, tickets_held
     */
    public static function recitalsForParent(int $parentContactId): array
    {
        $tid = current_tenant_id();
        $fam = self::getFamilyByParent($parentContactId);
        if ($fam === null) { return []; }

        $kids = self::familyStudentIds((int) $fam['id']);
        if ($kids === []) { return []; }
        $in = implode(',', array_map('intval', $kids));

        $rows = Database::rows(
            "SELECT DISTINCT r.*
               FROM studio_recitals r
               JOIN studio_recital_pieces p ON p.recital_id = r.id AND p.tenant_id = r.tenant_id
               JOIN studio_enrollments e ON e.series_id = p.series_id AND e.tenant_id = p.tenant_id
              WHERE r.tenant_id = ? AND r.status = 'published'
                AND e.student_id IN ($in) AND e.status IN ('active','trial')
           ORDER BY COALESCE(r.recital_date, '9999-12-31') ASC",
            [$tid]
        );

        foreach ($rows as &$r) {
            $rid = (int) $r['id'];

            $r['performers'] = Database::rows(
                "SELECT c.display_name AS student_name, s.name AS class_name,
                        p.title, p.position, p.id AS piece_id
                   FROM studio_recital_pieces p
                   JOIN studio_enrollments e ON e.series_id = p.series_id AND e.tenant_id = p.tenant_id
                   JOIN studio_class_series s ON s.id = p.series_id AND s.tenant_id = p.tenant_id
                   LEFT JOIN contacts c ON c.id = e.student_id
                  WHERE p.tenant_id = ? AND p.recital_id = ?
                    AND e.student_id IN ($in) AND e.status IN ('active','trial')
               ORDER BY p.position ASC",
                [$tid, $rid]
            );

            // Read from the fee ledger, not from studio_costumes. The wardrobe
            // table no longer prices anything, and summing both is what showed
            // a parent the same $95 twice in two different cards.
            $r['costume_due_cents'] = (int) Database::value(
                "SELECT COALESCE(SUM(amount_cents),0) FROM studio_fees
                  WHERE tenant_id = ? AND family_id = ?
                    AND kind IN ('costume','tights') AND status = 'pending'",
                [$tid, (int) $fam['id']]
            );

            // How far through the wardrobe process this family's dancers are —
            // the question studio_costumes now exists to answer.
            $r['costume_records'] = (int) Database::value(
                "SELECT COUNT(*) FROM studio_costumes co
                   JOIN studio_recital_pieces p ON p.id = co.piece_id AND p.tenant_id = co.tenant_id
                  WHERE co.tenant_id = ? AND p.recital_id = ? AND co.student_id IN ($in)",
                [$tid, $rid]
            );
            $r['costume_ready'] = (int) Database::value(
                "SELECT COUNT(*) FROM studio_costumes co
                   JOIN studio_recital_pieces p ON p.id = co.piece_id AND p.tenant_id = co.tenant_id
                  WHERE co.tenant_id = ? AND p.recital_id = ? AND co.student_id IN ($in)
                    AND co.status IN ('received','distributed')",
                [$tid, $rid]
            );

            $r['tickets_held'] = (int) Database::value(
                "SELECT COALESCE(SUM(quantity),0) FROM studio_recital_tickets
                  WHERE tenant_id = ? AND recital_id = ? AND family_id = ? AND status IN ('reserved','paid')",
                [$tid, $rid, (int) $fam['id']]
            );
        }
        unset($r);

        return $rows;
    }

    /**
     * Seats still available, or null when the studio doesn't track a count.
     * Cancelled and refunded orders release their seats.
     */
    public static function recitalSeatsLeft(int $recitalId): ?int
    {
        $r = self::getRecital($recitalId);
        if ($r === null || $r['seats_total'] === null) { return null; }
        $out = (int) Database::value(
            "SELECT COALESCE(SUM(quantity),0) FROM studio_recital_tickets
              WHERE tenant_id = ? AND recital_id = ? AND status IN ('reserved','paid')",
            [current_tenant_id(), $recitalId]
        );
        return max(0, (int) $r['seats_total'] - $out);
    }

    /**
     * Reserve seats for a family. Returns the new ticket row id.
     *
     * Reserved rather than paid: the row exists before checkout so the seats
     * are held while the parent is on Stripe, and markTicketPaid() promotes it
     * on return. An abandoned checkout leaves a reserved row an admin can see
     * and cancel, which is better than losing the intent entirely.
     */
    public static function reserveTickets(int $recitalId, int $familyId, int $quantity, array $who = []): int
    {
        $r = self::getRecital($recitalId);
        if ($r === null || $r['status'] !== 'published') {
            throw new \RuntimeException(__('studio_recital_closed', 'Tickets are not on sale for this show.'));
        }
        $quantity = max(1, min(20, $quantity));   // a sane per-order ceiling

        $left = self::recitalSeatsLeft($recitalId);
        if ($left !== null && $quantity > $left) {
            throw new \RuntimeException(sprintf(__('studio_seats_short', 'Only %d seat(s) left.'), $left));
        }

        return Database::insert('studio_recital_tickets', [
            'tenant_id'       => current_tenant_id(),
            'recital_id'      => $recitalId,
            'family_id'       => $familyId ?: null,
            'purchaser_id'    => (int) ($who['contact_id'] ?? 0) ?: null,
            'purchaser_name'  => trim((string) ($who['name'] ?? '')) ?: null,
            'purchaser_email' => trim((string) ($who['email'] ?? '')) ?: null,
            'quantity'        => $quantity,
            'amount_cents'    => $quantity * (int) $r['ticket_price_cents'],
            'currency'        => (string) ($r['currency'] ?? 'USD'),
            'status'          => 'reserved',
            'reference'       => strtoupper(bin2hex(random_bytes(4))),
        ]);
    }

    /** Promote a reserved order to paid. Idempotent, like the tuition path. */
    public static function markTicketPaid(int $ticketId, int $amountCents = 0, string $sessionId = '', string $paymentIntent = ''): bool
    {
        $tid = current_tenant_id();
        $row = Database::row('SELECT * FROM studio_recital_tickets WHERE tenant_id = ? AND id = ?', [$tid, $ticketId]);
        if ($row === null || (string) $row['status'] === 'paid') { return false; }

        $chargeId = null;
        if (class_exists('StripePaymentAPI') && $sessionId !== '') {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'studio',
                'source_id'                => 'ticket:' . $ticketId,
                'stripe_session_id'        => $sessionId,
                'stripe_payment_intent_id' => $paymentIntent,
                'customer_email'           => (string) ($row['purchaser_email'] ?? ''),
                'amount_cents'             => $amountCents ?: (int) $row['amount_cents'],
                'currency'                 => (string) ($row['currency'] ?? 'USD'),
                'status'                   => 'succeeded',
            ]);
        }

        Database::update('studio_recital_tickets', [
            'status'       => 'paid',
            'amount_cents' => $amountCents ?: (int) $row['amount_cents'],
            'charge_id'    => $chargeId ? (int) $chargeId : null,
            'updated_at'   => slate_db_now(),
        ], 'tenant_id = ? AND id = ?', [$tid, $ticketId]);

        return true;
    }

    // ── Reports ───────────────────────────────────────────────

    /**
     * Money in and money owed.
     *
     * Paid amounts live in the enrollment's meta JSON rather than a column, so
     * this reads the rows and sums in PHP. That's deliberate at studio scale
     * (hundreds of enrollments, not millions) and avoids depending on MySQL's
     * JSON functions, which the shared hosting this deploys to may not have.
     *
     * Outstanding is the discounted tuition of every active unpaid enrollment,
     * computed through the same calculator that bills them — a report that
     * disagrees with the invoice is worse than no report.
     *
     * @return array{collected_cents:int,outstanding_cents:int,paid_count:int,
     *               unpaid_count:int,by_month:array,by_class:array}
     */
    public static function reportRevenue(): array
    {
        $rows = Database::rows(
            "SELECT e.id, e.student_id, e.series_id, e.status, e.meta, s.name AS series_name
               FROM studio_enrollments e
               JOIN studio_class_series s ON s.id = e.series_id AND s.tenant_id = e.tenant_id
              WHERE e.tenant_id = ?",
            [current_tenant_id()]
        );

        $out = ['collected_cents' => 0, 'outstanding_cents' => 0, 'paid_count' => 0,
                'unpaid_count' => 0, 'by_month' => [], 'by_class' => []];

        foreach ($rows as $r) {
            $class = (string) $r['series_name'];
            $out['by_class'][$class] = $out['by_class'][$class]
                ?? ['collected_cents' => 0, 'outstanding_cents' => 0, 'enrolled' => 0];

            $paid = self::enrollmentPaidInfo($r['meta'] ?? null);
            $billable = in_array((string) $r['status'], ['active', 'trial'], true);

            if (!empty($paid['paid'])) {
                $cents = (int) $paid['paid_cents'];
                $out['collected_cents'] += $cents;
                $out['paid_count']++;
                $out['by_class'][$class]['collected_cents'] += $cents;

                $meta  = $r['meta'] ? (json_decode((string) $r['meta'], true) ?: []) : [];
                $when  = (string) ($meta['paid_at'] ?? '');
                $month = $when !== '' ? substr($when, 0, 7) : __('studio_unknown', 'Unknown');
                $out['by_month'][$month] = ($out['by_month'][$month] ?? 0) + $cents;
            } elseif ($billable) {
                try {
                    $due = self::calculateTuition((int) $r['student_id'], (int) $r['series_id'])->minor;
                } catch (\Throwable $e) {
                    $due = 0;   // a deleted series shouldn't blank the whole report
                }
                $out['outstanding_cents'] += $due;
                $out['unpaid_count']++;
                $out['by_class'][$class]['outstanding_cents'] += $due;
            }

            if ($billable) { $out['by_class'][$class]['enrolled']++; }
        }

        krsort($out['by_month']);
        uasort($out['by_class'], static fn ($a, $b) =>
            ($b['collected_cents'] + $b['outstanding_cents']) <=> ($a['collected_cents'] + $a['outstanding_cents']));

        return $out;
    }

    /**
     * How full the studio is: counts by status, and per-class fill against
     * capacity so an owner can see what to open another section of.
     *
     * @return array{active:int,waitlist:int,dropped:int,classes:array}
     */
    public static function reportEnrollment(): array
    {
        $tid = current_tenant_id();

        $byStatus = [];
        foreach (Database::rows(
            "SELECT status, COUNT(*) AS n FROM studio_enrollments WHERE tenant_id = ? GROUP BY status", [$tid]
        ) as $r) { $byStatus[(string) $r['status']] = (int) $r['n']; }

        $classes = Database::rows(
            "SELECT s.id, s.name, s.capacity, s.is_active,
                    SUM(CASE WHEN e.status IN ('active','trial') THEN 1 ELSE 0 END) AS enrolled,
                    SUM(CASE WHEN e.status = 'waitlist' THEN 1 ELSE 0 END)          AS waitlist
               FROM studio_class_series s
               LEFT JOIN studio_enrollments e ON e.series_id = s.id AND e.tenant_id = s.tenant_id
              WHERE s.tenant_id = ?
           GROUP BY s.id, s.name, s.capacity, s.is_active
           ORDER BY s.name",
            [$tid]
        );
        foreach ($classes as &$c) {
            $cap = max(1, (int) $c['capacity']);
            $c['enrolled'] = (int) $c['enrolled'];
            $c['waitlist'] = (int) $c['waitlist'];
            $c['fill_pct'] = (int) round(($c['enrolled'] / $cap) * 100);
        }
        unset($c);

        return [
            'active'   => ($byStatus['active'] ?? 0) + ($byStatus['trial'] ?? 0),
            'waitlist' => $byStatus['waitlist'] ?? 0,
            'dropped'  => $byStatus['dropped'] ?? 0,
            'classes'  => $classes,
        ];
    }

    /**
     * Attendance rate per class, plus how much of the register is actually
     * being taken — an unmarked session looks identical to a well-attended one
     * in any rate that ignores it, which is how attendance reports mislead.
     *
     * @return array{present:int,late:int,absent:int,excused:int,rate:int,
     *               sessions_past:int,sessions_marked:int,classes:array}
     */
    public static function reportAttendance(): array
    {
        $tid = current_tenant_id();

        $tally = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
        foreach (Database::rows(
            "SELECT status, COUNT(*) AS n FROM studio_attendance WHERE tenant_id = ? GROUP BY status", [$tid]
        ) as $r) {
            $s = (string) $r['status'];
            if (isset($tally[$s])) { $tally[$s] = (int) $r['n']; }
        }
        $counted = array_sum($tally);
        $here    = $tally['present'] + $tally['late'];

        $pastSessions = (int) Database::value(
            "SELECT COUNT(*) FROM studio_class_occurrences
              WHERE tenant_id = ? AND occurrence_date < CURDATE() AND status = 'scheduled'", [$tid]);
        $markedSessions = (int) Database::value(
            "SELECT COUNT(DISTINCT o.id)
               FROM studio_class_occurrences o
               JOIN studio_attendance a ON a.occurrence_id = o.id AND a.tenant_id = o.tenant_id
              WHERE o.tenant_id = ? AND o.occurrence_date < CURDATE()", [$tid]);

        $classes = Database::rows(
            "SELECT s.name,
                    SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS here,
                    COUNT(a.id) AS marked
               FROM studio_attendance a
               JOIN studio_class_occurrences o ON o.id = a.occurrence_id AND o.tenant_id = a.tenant_id
               JOIN studio_class_series s ON s.id = o.series_id AND s.tenant_id = o.tenant_id
              WHERE a.tenant_id = ?
           GROUP BY s.id, s.name
           ORDER BY s.name",
            [$tid]
        );
        foreach ($classes as &$c) {
            $m = max(1, (int) $c['marked']);
            $c['rate'] = (int) round(((int) $c['here'] / $m) * 100);
        }
        unset($c);

        return $tally + [
            'rate'            => $counted > 0 ? (int) round(($here / $counted) * 100) : 0,
            'sessions_past'   => $pastSessions,
            'sessions_marked' => $markedSessions,
            'classes'         => $classes,
        ];
    }

    // ── Term dates + waiver ───────────────────────────────────

    /**
     * Default term dates for a new class, so an admin creating six classes for
     * the same term types the dates once in Settings rather than twelve times.
     *
     * @return array{start:string,end:string}
     */
    public static function termDefaults(): array
    {
        return [
            'start' => (string) Database::setting('studio.term_start'),
            'end'   => (string) Database::setting('studio.term_end'),
        ];
    }

    /**
     * The Forms form used as the studio's liability waiver, or null.
     *
     * Studio does not implement signatures — the Forms plugin already has an
     * e-signature field and a submission store, so this is a pointer at one of
     * its forms rather than a parallel system.
     */
    public static function waiverForm(): ?array
    {
        $id = (int) Database::setting('studio.waiver_form_id');
        if ($id <= 0) { return null; }
        try {
            return Database::row(
                'SELECT id, slug, title FROM forms_definitions WHERE tenant_id = ? AND id = ?',
                [current_tenant_id(), $id]
            );
        } catch (\Throwable $e) {
            return null;   // Forms uninstalled — the waiver setting goes quiet
        }
    }

    /** Published forms the admin can pick from. Empty if Forms isn't installed. */
    public static function availableForms(): array
    {
        try {
            return Database::rows(
                "SELECT id, slug, title FROM forms_definitions
                  WHERE tenant_id = ? AND is_active = 1 ORDER BY title",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Has this address submitted the waiver form?
     *
     * Matched on the submitter's email because that is the only identifier
     * Forms records for a public submission — a parent who signs from a
     * different address will read as unsigned, which is the safe direction to
     * be wrong in for a liability document.
     */
    public static function waiverSignedBy(string $email): bool
    {
        $email = strtolower(trim($email));
        $form  = self::waiverForm();
        if ($form === null || $email === '') { return false; }
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM forms_submissions
                  WHERE tenant_id = ? AND form_id = ? AND LOWER(submitter_email) = ?",
                [current_tenant_id(), (int) $form['id'], $email]
            ) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Persist the discount curves. Values are `threshold => percent off`. */
    public static function saveDiscountPolicy(array $multiClass, array $sibling): void
    {
        // Round-trip through the value object so anything nonsensical is
        // dropped before it reaches settings, not after.
        $policy = new DiscountPolicy($multiClass, $sibling);
        Database::setSetting('studio.discount_multi',   json_encode($policy->multiClassTiers()));
        Database::setSetting('studio.discount_sibling', json_encode($policy->siblingTiers()));
        unset(self::$discountCache[current_tenant_id()]);
    }

    // ── Rooms and scheduling (the booking adapter) ────────────────
    //
    // Rooms are booking_resources, borrowed rather than duplicated: a studio
    // that already runs private lessons through Booking has its rooms defined
    // once, and a second studio_rooms table would immediately disagree with
    // them. The dependency is one-way and optional — Booking may be inactive,
    // in which case rooms are simply unavailable and everything else still
    // works. Nothing here writes to Booking.
    //
    // The conflict RULE is not borrowed. BookingAPI::freeResourceId() sums
    // party_size against capacity because several appointments share a room;
    // a class occupies the studio exclusively for its hour. See
    // ScheduleConflict for why that is a different question.

    /**
     * Rooms available to schedule into, or [] when Booking is not installed.
     *
     * @return array<int,array{id:int,name:string,capacity:int}>
     */
    public static function roomOptions(): array
    {
        if (!class_exists('BookingAPI') || !method_exists('BookingAPI', 'getResources')) {
            return [];
        }
        try {
            $out = [];
            foreach (BookingAPI::getResources(true) as $r) {
                $out[] = ['id'       => (int) $r['id'],
                          'name'     => (string) $r['name'],
                          'capacity' => (int) ($r['capacity'] ?? 0)];
            }
            return $out;
        } catch (\Throwable $e) {
            // A Booking schema mid-migration must not take the Classes page
            // down; losing the room picker is recoverable, a 500 is not.
            return [];
        }
    }

    /** True when rooms can be assigned at all. */
    public static function roomsAvailable(): bool
    {
        return self::roomOptions() !== [];
    }

    /**
     * The timetable as comparable blocks — active series only, since a
     * deactivated class is not occupying anything.
     *
     * @return array<int,array>
     */
    public static function scheduleBlocks(): array
    {
        return Database::rows(
            "SELECT s.id, s.name, s.day_of_week, s.start_time, s.end_time,
                    s.room_id, s.instructor_id, c.display_name AS instructor_name
               FROM studio_class_series s
               LEFT JOIN contacts c ON c.id = s.instructor_id
              WHERE s.tenant_id = ? AND s.is_active = 1
           ORDER BY s.day_of_week, s.start_time",
            [current_tenant_id()]
        );
    }

    /**
     * Conflicts a proposed or edited class would cause.
     *
     * Pass the series id in $candidate['id'] when editing so the row being
     * saved is not reported as clashing with itself.
     *
     * @return array<int,array{kind:string,with:array}>
     */
    public static function scheduleConflictsFor(array $candidate): array
    {
        return ScheduleConflict::against($candidate, self::scheduleBlocks());
    }

    /** Every clash across the whole timetable, each pair reported once. */
    public static function scheduleConflicts(): array
    {
        return ScheduleConflict::findAll(self::scheduleBlocks());
    }

    /**
     * Room name by id, for display. Falls back to "Room #n" so a room deleted
     * in Booking still renders as something meaningful rather than blank.
     */
    public static function roomName(?int $roomId): string
    {
        if ($roomId === null || $roomId <= 0) { return ''; }
        foreach (self::roomOptions() as $r) {
            if ($r['id'] === $roomId) { return $r['name']; }
        }
        return sprintf('Room #%d', $roomId);
    }

    // ── Fees: registration, recital, costumes, tights ─────────────
    //
    // Tuition lives on the enrolment; everything else lives in studio_fees.
    // See 0007_studio_fees for why the instalment split is stored rather than
    // recomputed. The arithmetic is all in FeeSchedule (pure, unit-tested) —
    // these methods only load settings, read enrolments, and write rows.

    /** Settings keys backing FeeSchedule, in one place so both sides agree. */
    private const FEE_KEYS = [
        'registration_cents', 'recital_first_cents', 'recital_additional_cents',
        'recital_due', 'costume_cents', 'tights_cents', 'costume_instalments',
    ];

    /**
     * The tenant's fee schedule, or the shipped defaults where unset.
     * Memoised per request: fee generation reads this once per student.
     */
    /**
     * Per-tenant memo. A class property rather than a `static` inside the
     * method so saveFeeSchedule() can clear it — the natural sequence is
     * "save the fees, then bill against them", and a stale memo there charges
     * the old amounts for the rest of the request. Same trap the holiday
     * calendar hit.
     *
     * @var array<int,FeeSchedule>
     */
    private static array $feeCache = [];

    public static function feeSchedule(): FeeSchedule
    {
        $tid = current_tenant_id();
        if (isset(self::$feeCache[$tid])) { return self::$feeCache[$tid]; }

        $raw = [];
        foreach (self::FEE_KEYS as $k) {
            $v = Database::setting('studio.fee_' . $k);
            if ($v !== null && $v !== '') { $raw[$k] = $v; }
        }
        return self::$feeCache[$tid] = FeeSchedule::fromArray($raw);
    }

    /** Drop the memoised fee schedule for this tenant. */
    public static function forgetFeeSchedule(): void
    {
        unset(self::$feeCache[current_tenant_id()]);
    }

    /**
     * Persist the fee schedule. Round-trips through the value object first so
     * a rejected instalment plan is never written — a stored 90% plan would
     * under-bill every family silently.
     *
     * @param array<string,mixed> $values
     */
    public static function saveFeeSchedule(array $values): void
    {
        $fs = FeeSchedule::fromArray($values);
        $out = [
            'registration_cents'       => (string) $fs->registrationCents(),
            'recital_first_cents'      => (string) $fs->recitalFeeBreakdown(1)[0],
            'recital_additional_cents' => (string) ($fs->recitalFeeForFamily(2) - $fs->recitalFeeForFamily(1)),
            'recital_due'              => substr($fs->recitalDueDate(2000), 5),
            'costume_cents'            => (string) $fs->costumeCents(),
            'tights_cents'             => (string) $fs->tightsCents(),
            'costume_instalments'      => (string) json_encode($fs->costumeInstalments()),
        ];
        foreach ($out as $k => $v) {
            Database::setSetting('studio.fee_' . $k, $v);
        }
        self::forgetFeeSchedule();
    }

    /**
     * Raise one fee, or leave an existing one alone.
     *
     * `dedupe_key` carries a UNIQUE index, so a second call with the same key
     * is a no-op rather than a duplicate bill — which is what makes the
     * generators below safe to re-run. Returns the row id either way, so a
     * caller can always link to what it just ensured.
     */
    public static function raiseFee(array $f): int
    {
        $tid = current_tenant_id();
        $key = trim((string) ($f['dedupe_key'] ?? ''));

        if ($key !== '') {
            $existing = Database::value(
                "SELECT id FROM studio_fees WHERE tenant_id = ? AND dedupe_key = ?",
                [$tid, $key]
            );
            if ($existing) { return (int) $existing; }
        }

        try {
            return Database::insert('studio_fees', [
                'tenant_id'     => $tid,
                'family_id'     => (int) ($f['family_id'] ?? 0),
                'student_id'    => isset($f['student_id']) ? (int) $f['student_id'] : null,
                'kind'          => (string) ($f['kind'] ?? 'other'),
                'series_id'     => isset($f['series_id'])  ? (int) $f['series_id']  : null,
                'recital_id'    => isset($f['recital_id']) ? (int) $f['recital_id'] : null,
                'label'         => (string) ($f['label'] ?? ''),
                'amount_cents'  => max(0, (int) ($f['amount_cents'] ?? 0)),
                'currency'      => (string) ($f['currency'] ?? 'USD'),
                'due_date'      => $f['due_date'] ?? null,
                'instalment_no' => max(1, (int) ($f['instalment_no'] ?? 1)),
                'instalment_of' => max(1, (int) ($f['instalment_of'] ?? 1)),
                'status'        => 'pending',
                'dedupe_key'    => $key !== '' ? $key : null,
                'meta'          => isset($f['meta']) ? json_encode($f['meta']) : null,
                'created_at'    => slate_db_now(),
                'updated_at'    => slate_db_now(),
            ]);
        } catch (\Throwable $e) {
            // Lost a race on the UNIQUE index — the other writer's row is the
            // one true bill. Re-read rather than surfacing a duplicate-key
            // error to a parent mid-checkout.
            if ($key !== '') {
                $id = Database::value(
                    "SELECT id FROM studio_fees WHERE tenant_id = ? AND dedupe_key = ?",
                    [$tid, $key]
                );
                if ($id) { return (int) $id; }
            }
            throw $e;
        }
    }

    /**
     * Raise one fee by hand, against a dancer.
     *
     * The generators cover what the policy bills on a calendar — costumes,
     * tights, recital, registration. This covers what they cannot know in
     * advance: the policy's "competition classes may have an additional
     * costume and/or crystal fee", and anything else a studio needs to put on
     * a statement once.
     *
     * The family is derived from the dancer rather than accepted from the
     * caller. A posted family_id that disagrees with the dancer would bill a
     * stranger, and nothing downstream would notice.
     *
     * No dedupe_key, unlike the generators: two identical hand-raised fees
     * are a legitimate thing to want (two costumes at the same price), so
     * idempotency here would be wrong rather than safe.
     *
     * @throws \RuntimeException when the input cannot produce a billable row
     */
    public static function raiseAdHocFee(int $studentId, string $label, int $amountCents, array $opts = []): int
    {
        $label = trim($label);
        $kind  = (string) ($opts['kind'] ?? 'other');
        if (!in_array($kind, ['registration', 'recital', 'costume', 'tights', 'other'], true)) {
            $kind = 'other';
        }

        if ($studentId <= 0 || $label === '' || $amountCents <= 0) {
            throw new \RuntimeException(__('studio_fee_add_invalid',
                'Pick a dancer, name the fee, and enter an amount above zero.'));
        }

        $familyId = (int) Database::value(
            "SELECT family_id FROM studio_family_members
              WHERE tenant_id = ? AND contact_id = ? AND relation = 'child'
              LIMIT 1",
            [current_tenant_id(), $studentId]
        );
        if ($familyId <= 0) {
            throw new \RuntimeException(__('studio_fee_add_no_family',
                'That dancer is not in a family, so there is nobody to bill.'));
        }

        $seriesId = (int) ($opts['series_id'] ?? 0);
        $due      = trim((string) ($opts['due_date'] ?? ''));

        return self::raiseFee([
            'family_id'    => $familyId,
            'student_id'   => $studentId,
            'kind'         => $kind,
            'series_id'    => $seriesId > 0 ? $seriesId : null,
            'label'        => $label,
            'amount_cents' => $amountCents,
            'due_date'     => $due !== '' ? $due : null,
        ]);
    }

    /**
     * Raise costume + tights fees for one student's active enrolments.
     *
     * Exemptions (technique at any age, ballet 8 & up) are decided by
     * FeeSchedule from the class's own style and age_min, so a studio that
     * retimes a class gets the right answer without touching this.
     *
     * @return int[] ids of every fee row now standing for this student
     */
    public static function generateCostumeFeesForStudent(int $studentId, int $seasonYear): array
    {
        $tid = current_tenant_id();
        $fs  = self::feeSchedule();

        $family = Database::value(
            "SELECT fm.family_id FROM studio_family_members fm
              WHERE fm.tenant_id = ? AND fm.contact_id = ? LIMIT 1",
            [$tid, $studentId]
        );
        if (!$family) { return []; }

        // s.is_active = 1 is load-bearing, not defensive. An enrolment can
        // outlive its class: deactivating a series hides it from the catalog
        // but leaves enrolment history intact (which is the point — a delete
        // would destroy attendance). Without this filter the generator bills a
        // $95 recital costume for a class that is not running. Company B's
        // catalog cull left 16 such enrolments across 7 retired classes.
        $classes = Database::rows(
            "SELECT s.id, s.name, s.style, s.age_min
               FROM studio_enrollments e
               JOIN studio_class_series s
                 ON s.id = e.series_id AND s.tenant_id = e.tenant_id
              WHERE e.tenant_id = ? AND e.student_id = ? AND e.status = 'active'
                AND s.is_active = 1
              ORDER BY s.name",
            [$tid, $studentId]
        );

        $ids      = [];
        $costumed = 0;

        foreach ($classes as $c) {
            $ageMin = $c['age_min'] === null ? null : (int) $c['age_min'];
            if ($fs->costumeExempt((string) $c['style'], $ageMin)) { continue; }
            $costumed++;

            foreach ($fs->splitCostume($fs->costumeCents(), $seasonYear) as $part) {
                $ids[] = self::raiseFee([
                    'family_id'     => (int) $family,
                    'student_id'    => $studentId,
                    'kind'          => 'costume',
                    'series_id'     => (int) $c['id'],
                    'label'         => sprintf('Costume — %s (%d of %d)',
                                        (string) $c['name'], $part['instalment_no'], $part['instalment_of']),
                    'amount_cents'  => $part['amount_cents'],
                    'due_date'      => $part['due_date'],
                    'instalment_no' => $part['instalment_no'],
                    'instalment_of' => $part['instalment_of'],
                    'dedupe_key'    => sprintf('costume:%d:%d:%d:%d',
                                        $seasonYear, $studentId, (int) $c['id'], $part['instalment_no']),
                ]);
            }
        }

        // Tights are once per student, and only if something is being costumed.
        if ($costumed > 0 && $fs->tightsCents() > 0) {
            foreach ($fs->splitCostume($fs->tightsCents(), $seasonYear) as $part) {
                $ids[] = self::raiseFee([
                    'family_id'     => (int) $family,
                    'student_id'    => $studentId,
                    'kind'          => 'tights',
                    'label'         => sprintf('Tights (%d of %d)',
                                        $part['instalment_no'], $part['instalment_of']),
                    'amount_cents'  => $part['amount_cents'],
                    'due_date'      => $part['due_date'],
                    'instalment_no' => $part['instalment_no'],
                    'instalment_of' => $part['instalment_of'],
                    // The instalment number MUST be part of the key. Without it
                    // both halves of a split tights charge collide on the
                    // UNIQUE index and the second is silently deduped away —
                    // billing the parent half of what was quoted.
                    'dedupe_key'    => sprintf('tights:%d:%d:%d',
                                        $seasonYear, $studentId, $part['instalment_no']),
                ]);
            }
        }

        return $ids;
    }

    /**
     * Raise the recital performance fee for one family.
     *
     * Charged per family at a taper, so it counts the family's actively
     * enrolled children rather than billing each student. A family whose
     * children have all dropped is charged nothing.
     */
    public static function generateRecitalFeeForFamily(int $familyId, int $recitalId, int $seasonYear): array
    {
        $tid = current_tenant_id();
        $fs  = self::feeSchedule();

        // Same is_active rule as the costume generator, for the same reason: a
        // child whose only enrolments are in retired classes is not in the
        // show, so the family must not be billed a performance fee for them.
        // Counting raw enrolments charged Company B families $150 for children
        // with nothing running.
        $children = (int) Database::value(
            "SELECT COUNT(DISTINCT e.student_id)
               FROM studio_enrollments e
               JOIN studio_family_members fm
                 ON fm.contact_id = e.student_id AND fm.tenant_id = e.tenant_id
               JOIN studio_class_series s
                 ON s.id = e.series_id AND s.tenant_id = e.tenant_id
              WHERE e.tenant_id = ? AND fm.family_id = ? AND e.status = 'active'
                AND s.is_active = 1",
            [$tid, $familyId]
        );
        if ($children <= 0) { return []; }

        $ids = [];
        foreach ($fs->recitalFeeBreakdown($children) as $i => $cents) {
            if ($cents <= 0) { continue; }
            $ids[] = self::raiseFee([
                'family_id'    => $familyId,
                'kind'         => 'recital',
                'recital_id'   => $recitalId,
                'label'        => $i === 0 ? 'Recital fee' : 'Recital fee — additional child',
                'amount_cents' => $cents,
                'due_date'     => $fs->recitalDueDate($seasonYear),
                'dedupe_key'   => sprintf('recital:%d:%d:%d', $recitalId, $familyId, $i),
            ]);
        }
        return $ids;
    }

    /**
     * Every fee in the tenant, joined to the people it concerns, for the admin
     * list. Void rows are included so a studio can answer "what happened to
     * that $95" — the page tabs them out of the default view rather than the
     * query hiding them.
     */
    public static function listFees(): array
    {
        return Database::rows(
            "SELECT fe.*,
                    st.display_name AS student_name,
                    pa.display_name AS parent_name,
                    se.name          AS series_name
               FROM studio_fees fe
               LEFT JOIN contacts st        ON st.id = fe.student_id
               LEFT JOIN studio_families f  ON f.id  = fe.family_id AND f.tenant_id = fe.tenant_id
               LEFT JOIN contacts pa        ON pa.id = f.primary_parent_id
               LEFT JOIN studio_class_series se ON se.id = fe.series_id AND se.tenant_id = fe.tenant_id
              WHERE fe.tenant_id = ?
           ORDER BY fe.due_date IS NULL, fe.due_date, fe.id",
            [current_tenant_id()]
        );
    }

    /** Reverse a fee raised in error. Paid rows are left alone. */
    public static function voidFee(int $feeId): bool
    {
        return Database::update('studio_fees',
            ['status' => 'void', 'updated_at' => slate_db_now()],
            "tenant_id = ? AND id = ? AND status = 'pending'",
            [current_tenant_id(), $feeId]) > 0;
    }

    // ── Announcements (targeted broadcast) ────────────────────────

    /**
     * Candidate rows for an audience, before deduplication.
     *
     * Returns rows, not recipients: Audience does the cleaning, and the raw
     * count is what lets the preview say "41 families (3 have no email)".
     *
     * @param array{kind:string,ref?:int,age_min?:int,age_max?:int} $spec
     */
    public static function announcementCandidates(array $spec): array
    {
        $tid  = current_tenant_id();
        $kind = Audience::normaliseKind($spec['kind'] ?? 'all');

        // Every audience resolves to PARENTS — the people who read studio
        // email — via the family that anchors each dancer.
        $base = "SELECT DISTINCT p.id AS contact_id, p.display_name AS name,
                        p.primary_email AS email
                   FROM studio_families f
                   JOIN contacts p ON p.id = f.primary_parent_id
                  WHERE f.tenant_id = ?";
        $args = [$tid];

        switch ($kind) {
            case 'class':
                $base .= " AND EXISTS (
                            SELECT 1 FROM studio_family_members fm
                              JOIN studio_enrollments e
                                ON e.student_id = fm.contact_id AND e.tenant_id = fm.tenant_id
                             WHERE fm.tenant_id = f.tenant_id AND fm.family_id = f.id
                               AND e.series_id = ? AND e.status IN ('active','trial'))";
                $args[] = (int) ($spec['ref'] ?? 0);
                break;

            case 'unpaid':
                $base .= " AND EXISTS (
                            SELECT 1 FROM studio_fees fe
                             WHERE fe.tenant_id = f.tenant_id AND fe.family_id = f.id
                               AND fe.status = 'pending')";
                break;

            case 'age':
                // Age is a property of the CLASS a dancer is in, not of the
                // contact — Studio has never stored a date of birth, and
                // inventing one here would be worse than using the class band
                // the studio already curates.
                $base .= " AND EXISTS (
                            SELECT 1 FROM studio_family_members fm
                              JOIN studio_enrollments e
                                ON e.student_id = fm.contact_id AND e.tenant_id = fm.tenant_id
                              JOIN studio_class_series s
                                ON s.id = e.series_id AND s.tenant_id = e.tenant_id
                             WHERE fm.tenant_id = f.tenant_id AND fm.family_id = f.id
                               AND e.status IN ('active','trial')
                               AND s.age_min >= ? AND s.age_max <= ?)";
                $args[] = (int) ($spec['age_min'] ?? 0);
                $args[] = (int) ($spec['age_max'] ?? 99);
                break;
        }

        return Database::rows($base . " ORDER BY p.display_name", $args);
    }

    /** Preview an audience: counts plus the deduplicated recipient list. */
    public static function announcementPreview(array $spec): array
    {
        $rows = self::announcementCandidates($spec);
        return [
            'summary'    => Audience::summarise($rows),
            'recipients' => Audience::recipients($rows),
        ];
    }

    /** A human label for the chosen audience, frozen into the history row. */
    public static function announcementAudienceLabel(array $spec): string
    {
        switch (Audience::normaliseKind($spec['kind'] ?? 'all')) {
            case 'class':
                $n = (string) Database::value(
                    "SELECT name FROM studio_class_series WHERE tenant_id = ? AND id = ?",
                    [current_tenant_id(), (int) ($spec['ref'] ?? 0)]);
                return $n !== '' ? $n : __('studio_ann_a_class', 'A class');
            case 'unpaid':
                return __('studio_ann_a_unpaid', 'Families with a balance');
            case 'age':
                return sprintf(__('studio_ann_a_age', 'Ages %d–%d'),
                    (int) ($spec['age_min'] ?? 0), (int) ($spec['age_max'] ?? 99));
            default:
                return __('studio_ann_a_all', 'All families');
        }
    }

    /**
     * Compose, send and record a broadcast.
     *
     * Each recipient gets their OWN message — never a shared To or Bcc. A
     * studio email that leaks the whole parent list is a data-protection
     * incident, and it is the default outcome of the obvious implementation.
     *
     * A recipient row is written before the attempt and updated after, so a
     * crash mid-send leaves an accurate partial record rather than silence.
     *
     * @return array{id:int,sent:int,failed:int}
     */
    public static function sendAnnouncement(string $subject, string $body, array $spec, ?int $userId = null): array
    {
        $tid     = current_tenant_id();
        $subject = trim($subject);
        $body    = trim($body);
        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException(
                __('studio_ann_need_both', 'An announcement needs a subject and a message.'));
        }

        $recipients = self::announcementPreview($spec)['recipients'];
        if (!$recipients) {
            throw new \InvalidArgumentException(
                __('studio_ann_no_one', 'That audience has nobody with an email address.'));
        }

        $annId = Database::insert('studio_announcements', [
            'tenant_id'       => $tid,
            'subject'         => $subject,
            'body'            => $body,
            'audience_kind'   => Audience::normaliseKind($spec['kind'] ?? 'all'),
            'audience_ref'    => isset($spec['ref']) ? (int) $spec['ref'] : null,
            'audience_label'  => self::announcementAudienceLabel($spec),
            'recipient_count' => count($recipients),
            'status'          => 'sending',
            'created_by'      => $userId,
            'created_at'      => slate_db_now(),
            'updated_at'      => slate_db_now(),
        ]);

        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $rid = Database::insert('studio_announcement_recipients', [
                'tenant_id'       => $tid,
                'announcement_id' => $annId,
                'contact_id'      => $r['contact_id'],
                'email'           => $r['email'],
                'name'            => $r['name'],
                'status'          => 'pending',
            ]);

            $ok  = false;
            $err = '';
            try {
                $personal = Audience::personalise($body, [
                    'first_name' => explode(' ', $r['name'])[0] ?? '',
                    'name'       => $r['name'],
                ]);
                $ok = class_exists('StudioMail')
                    ? StudioMail::sendAnnouncement($r['email'], $r['name'], $subject, $personal)
                    : false;
            } catch (\Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 250);
            }

            Database::update('studio_announcement_recipients', [
                'status'  => $ok ? 'sent' : 'failed',
                'error'   => $ok ? null : ($err !== '' ? $err : 'send returned false'),
                'sent_at' => $ok ? slate_db_now() : null,
            ], 'tenant_id = ? AND id = ?', [$tid, $rid]);

            $ok ? $sent++ : $failed++;
        }

        Database::update('studio_announcements', [
            'sent_count'   => $sent,
            'failed_count' => $failed,
            // "sent" only when everything landed. 38 of 41 is a different
            // situation from sent, and a green tick would hide it.
            'status'       => $failed === 0 ? 'sent' : 'failed',
            'sent_at'      => slate_db_now(),
            'updated_at'   => slate_db_now(),
        ], 'tenant_id = ? AND id = ?', [$tid, $annId]);

        return ['id' => $annId, 'sent' => $sent, 'failed' => $failed];
    }

    /** Announcement history, newest first. */
    public static function listAnnouncements(int $limit = 50): array
    {
        return Database::rows(
            "SELECT * FROM studio_announcements WHERE tenant_id = ?
              ORDER BY COALESCE(sent_at, created_at) DESC, id DESC LIMIT " . max(1, $limit),
            [current_tenant_id()]
        );
    }

    /** Who one announcement went to, and what happened for each. */
    public static function announcementRecipients(int $announcementId): array
    {
        return Database::rows(
            "SELECT * FROM studio_announcement_recipients
              WHERE tenant_id = ? AND announcement_id = ?
           ORDER BY status = 'sent', name, email",
            [current_tenant_id(), $announcementId]
        );
    }

    // ── Tuition plans and the registration fee ────────────────────

    /**
     * @var array<int,TuitionPlan> Per-tenant memo, clearable by the setter.
     *      Third time this pattern has bitten in this class — a `static`
     *      inside the getter is unreachable from the setter, so "save the
     *      plan, then quote against it" silently used the old one.
     */
    private static array $planCache = [];

    /** The tenant's billing cadence, or pay-in-full when unset. */
    public static function tuitionPlan(): TuitionPlan
    {
        $tid = current_tenant_id();
        if (isset(self::$planCache[$tid])) { return self::$planCache[$tid]; }

        $cadence = (string) Database::setting('studio.tuition_cadence');
        $n       = (int) Database::setting('studio.tuition_instalments');
        if ($cadence === '' && $n <= 1) { return self::$planCache[$tid] = TuitionPlan::inFull(); }

        return self::$planCache[$tid] = TuitionPlan::make(
            $cadence !== '' ? $cadence : 'current', $n > 0 ? $n : 1);
    }

    public static function saveTuitionPlan(string $cadence, int $instalments): void
    {
        $plan = TuitionPlan::make($cadence, $instalments);
        Database::setSetting('studio.tuition_cadence',     $plan->cadence());
        Database::setSetting('studio.tuition_instalments', (string) $plan->instalments());
        unset(self::$planCache[current_tenant_id()]);
    }

    /**
     * Has this family already been charged registration?
     *
     * Registration is once per family for as long as they are with the studio,
     * not once per term and not once per dancer — a second child joining does
     * not pay it again. The fee ledger is the record, so this is a lookup
     * rather than a flag that could drift from what was actually billed.
     */
    public static function registrationCharged(int $familyId): bool
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM studio_fees
              WHERE tenant_id = ? AND family_id = ? AND kind = 'registration'
                AND status <> 'void'",
            [current_tenant_id(), $familyId]
        ) > 0;
    }

    /**
     * Raise the registration fee if this family owes one.
     *
     * No-ops when registration is free (the default) — a $0 line on a
     * statement is noise, and the policies page already says it is free.
     *
     * @return int fee id, or 0 when nothing was raised
     */
    public static function raiseRegistrationFee(int $familyId): int
    {
        $fs = self::feeSchedule();
        if ($fs->registrationIsFree()) { return 0; }
        if (self::registrationCharged($familyId)) { return 0; }

        return self::raiseFee([
            'family_id'    => $familyId,
            'kind'         => 'registration',
            'label'        => __('studio_fee_registration', 'Registration fee'),
            'amount_cents' => $fs->registrationCents(),
            'due_date'     => date('Y-m-d'),
            'dedupe_key'   => 'registration:' . $familyId,
        ]);
    }

    /**
     * An itemised quote for one enrolment: what is charged, why, and when.
     *
     * Built because a parent currently sees a single figure with no way to
     * tell how it was reached — the discount they were promised is invisible,
     * and so is the registration fee. Every line here is one row the checkout
     * can show.
     *
     * @return array{
     *   lines: array<int,array{label:string,amount_cents:int,kind:string}>,
     *   total_cents: int, tuition_cents: int, discount_cents: int,
     *   schedule: array, plan: string
     * }
     */
    public static function tuitionQuote(int $studentId, int $seriesId): array
    {
        $series = self::getClassSeries($seriesId);
        if ($series === null) {
            throw new \InvalidArgumentException(__('studio_class_gone', 'That class no longer exists.'));
        }

        $listCents = (int) $series['price_cents'];
        $netCents  = self::calculateTuition($studentId, $seriesId)->minor;
        $discount  = max(0, $listCents - $netCents);

        $lines = [[
            'label'        => (string) $series['name'],
            'amount_cents' => $listCents,
            'kind'         => 'tuition',
        ]];
        if ($discount > 0) {
            $lines[] = [
                'label'        => __('studio_quote_discount', 'Multi-class / sibling discount'),
                'amount_cents' => -$discount,
                'kind'         => 'discount',
            ];
        }

        // Registration rides on the same checkout, but only the first time and
        // only if the studio charges one.
        $regCents = 0;
        $fam = Database::row(
            "SELECT fm.family_id FROM studio_family_members fm
              WHERE fm.tenant_id = ? AND fm.contact_id = ? LIMIT 1",
            [current_tenant_id(), $studentId]
        );
        $fs = self::feeSchedule();
        if ($fam && !$fs->registrationIsFree() && !self::registrationCharged((int) $fam['family_id'])) {
            $regCents = $fs->registrationCents();
            $lines[] = [
                'label'        => __('studio_fee_registration', 'Registration fee'),
                'amount_cents' => $regCents,
                'kind'         => 'registration',
            ];
        }

        $plan     = self::tuitionPlan();
        $total    = $netCents + $regCents;
        $schedule = $plan->schedule($netCents, (string) $series['session_start']);

        return [
            'lines'          => $lines,
            'tuition_cents'  => $netCents,
            'discount_cents' => $discount,
            'total_cents'    => $total,
            'schedule'       => $schedule,
            'plan'           => $plan->describe(),
        ];
    }

    // ── Payment reminders ─────────────────────────────────────────

    /** The tenant's reminder schedule, or the shipped default. */
    public static function reminderPolicy(): ReminderPolicy
    {
        $tid = current_tenant_id();
        if (isset(self::$reminderCache[$tid])) { return self::$reminderCache[$tid]; }
        return self::$reminderCache[$tid] = ReminderPolicy::fromString(
            (string) Database::setting('studio.fee_reminder_steps')
        );
    }

    /** Which reminder steps have already gone out for a fee. @return int[] */
    public static function remindersSentFor(?string $metaJson): array
    {
        if ($metaJson === null || trim($metaJson) === '') { return []; }
        $m = json_decode($metaJson, true);
        if (!is_array($m) || !isset($m['reminders']) || !is_array($m['reminders'])) { return []; }
        return array_map('intval', $m['reminders']);
    }

    /**
     * Outstanding fees needing a nudge today, grouped by family.
     *
     * Grouped, not listed, on purpose: a parent with eleven costume
     * instalments must get one email, not eleven. The family's most urgent
     * step drives the subject and tone; every fee that qualifies is listed
     * inside so the parent sees one complete picture of what they owe.
     *
     * Only families with an email address are returned — there is nowhere to
     * send otherwise, and marking a reminder "sent" that never left would make
     * the ledger lie.
     *
     * @return array<int,array{family_id:int,parent:array,step:int,fees:array}>
     */
    public static function feeRemindersDue(?string $today = null): array
    {
        $tid    = current_tenant_id();
        $today  = $today ?: date('Y-m-d');
        $policy = self::reminderPolicy();

        $rows = Database::rows(
            "SELECT fe.id, fe.family_id, fe.label, fe.amount_cents, fe.due_date, fe.meta,
                    f.primary_parent_id,
                    c.display_name AS parent_name, c.primary_email AS parent_email
               FROM studio_fees fe
               JOIN studio_families f ON f.id = fe.family_id AND f.tenant_id = fe.tenant_id
               LEFT JOIN contacts c   ON c.id = f.primary_parent_id
              WHERE fe.tenant_id = ? AND fe.status = 'pending' AND fe.due_date IS NOT NULL
           ORDER BY fe.family_id, fe.due_date, fe.id",
            [$tid]
        );

        $byFamily = [];
        foreach ($rows as $r) {
            $email = trim((string) ($r['parent_email'] ?? ''));
            if ($email === '' || !str_contains($email, '@')) { continue; }

            $step = $policy->stepFor(
                (string) $r['due_date'], $today, self::remindersSentFor($r['meta'] ?? null)
            );
            if ($step === null) { continue; }

            $fid = (int) $r['family_id'];
            if (!isset($byFamily[$fid])) {
                $byFamily[$fid] = [
                    'family_id' => $fid,
                    'parent'    => ['id'    => (int) $r['primary_parent_id'],
                                    'name'  => (string) ($r['parent_name'] ?? ''),
                                    'email' => $email],
                    'step'      => $step,
                    'fees'      => [],
                ];
            }
            // The most urgent step across the family sets the tone.
            if ($step > $byFamily[$fid]['step']) { $byFamily[$fid]['step'] = $step; }
            $byFamily[$fid]['fees'][] = $r + ['step' => $step];
        }

        return array_values($byFamily);
    }

    /**
     * Stamp a reminder as sent. Called only after the mailer returns, so a
     * send that threw is retried on the next run rather than silently skipped.
     */
    public static function recordFeeReminder(int $feeId, int $step): void
    {
        $tid = current_tenant_id();
        $row = Database::row("SELECT meta FROM studio_fees WHERE tenant_id = ? AND id = ?", [$tid, $feeId]);
        if (!$row) { return; }

        $meta = [];
        if (trim((string) ($row['meta'] ?? '')) !== '') {
            $decoded = json_decode((string) $row['meta'], true);
            if (is_array($decoded)) { $meta = $decoded; }
        }
        $sent = isset($meta['reminders']) && is_array($meta['reminders'])
              ? array_map('intval', $meta['reminders']) : [];
        if (!in_array($step, $sent, true)) { $sent[] = $step; }
        sort($sent);
        $meta['reminders']      = $sent;
        $meta['reminded_at']    = slate_db_now();

        Database::update('studio_fees',
            ['meta' => json_encode($meta), 'updated_at' => slate_db_now()],
            'tenant_id = ? AND id = ?', [$tid, $feeId]);
    }

    /** Every fee standing against a family, oldest due first. */
    public static function feesForFamily(int $familyId, bool $openOnly = false): array
    {
        $sql = "SELECT * FROM studio_fees WHERE tenant_id = ? AND family_id = ?";
        if ($openOnly) { $sql .= " AND status = 'pending'"; }
        $sql .= " ORDER BY due_date IS NULL, due_date, id";
        return Database::rows($sql, [current_tenant_id(), $familyId]);
    }

    /** What a family still owes, in cents. */
    public static function feesOutstandingForFamily(int $familyId): int
    {
        return (int) Database::value(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM studio_fees
              WHERE tenant_id = ? AND family_id = ? AND status = 'pending'",
            [current_tenant_id(), $familyId]
        );
    }

    /**
     * Settle a fee. Idempotent on the transition, mirroring
     * markEnrollmentPaid: a late webhook after a checkout-return reconcile
     * must not double-fire the paid hook or overwrite the first payment.
     */
    public static function markFeePaid(
        int $feeId, int $amountCents, string $method = 'stripe', string $chargeId = ''
    ): bool {
        $tid = current_tenant_id();
        $row = Database::row(
            "SELECT id, status FROM studio_fees WHERE tenant_id = ? AND id = ?",
            [$tid, $feeId]
        );
        if (!$row || $row['status'] === 'paid') { return false; }

        Database::update('studio_fees', [
            'status'      => 'paid',
            'paid_at'     => slate_db_now(),
            'paid_cents'  => max(0, $amountCents),
            'paid_method' => $method,
            'charge_id'   => $chargeId !== '' ? $chargeId : null,
            'updated_at'  => slate_db_now(),
        ], 'tenant_id = ? AND id = ?', [$tid, $feeId]);

        if (class_exists('Hook')) {
            Hook::doAction('studio_fee_paid', $feeId, $amountCents);
        }
        return true;
    }

    /**
     * Void every pending fee raised for a student against one class.
     *
     * Called when an enrolment is dropped: the parent should stop being
     * chased for a costume they will not wear. Paid rows are left alone —
     * refunding is a separate, deliberate act.
     */
    public static function voidFeesForEnrollment(int $studentId, int $seriesId): int
    {
        return Database::update('studio_fees', [
            'status'     => 'void',
            'updated_at' => slate_db_now(),
        ],
        "tenant_id = ? AND student_id = ? AND series_id = ? AND status = 'pending'",
        [current_tenant_id(), $studentId, $seriesId]);
    }
}
