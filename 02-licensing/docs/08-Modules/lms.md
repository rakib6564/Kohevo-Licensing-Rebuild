# 08 — LMS Module (Future)

**Status:** Draft · **Applies to:** Slate v3.x

## Purpose

Learning management: courses, lessons, enrollments, progress tracking, and
(optionally) paid access — built on Identity, the Content/render stack, and
Payments.

## Bounded context

**Content + Commerce** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Identity | the learner is a **Contact**; enrollment keyed by `contact_id` |
| Rendering + Blocks | lessons rendered as Sections/Blocks (video, text, quiz) |
| Payments (`PaymentGateway`) | paid course access via `Money` |
| `membership@1` (optional) | membership-gated courses |
| Notifications | progress nudges, completion |

## Provides

- Course/lesson blocks; events `course.enrolled`, `lesson.completed`,
  `course.completed`.

## Owns

- `lms_courses`, `_lessons`, `_enrollments`, `_progress`, `_quizzes` — enrollments
  and progress keyed by `contact_id`.
- **Does NOT own:** the learner (Contact), lesson rendering (Block Registry),
  payments (gateway), membership gating (consumed via `membership@1`).

## Composition

LMS shows the platform's reuse at its fullest: content comes from the **same block
system** as the CMS, access control can come from **Membership**, payment from the
**gateway**, and the learner is the **same Contact** the CRM tracks. A course
purchase emits `order.paid`/`payment.succeeded`; the LMS enrolls by listening — no
coupling to Shop or the gateway internals.

## Routes & admin

- Public: course catalog, course/lesson player (progress-gated), certificates.
- Admin: courses, lessons, enrollments, progress reports.

## Integration events

- **Emits:** `course.enrolled`, `course.completed` → CRM activity, notifications,
  certificates.
- **Subscribes:** `payment.succeeded` (grant access), `membership.started`
  (unlock gated courses), `contact.merged`.

---

## Related

- [../05-Rendering/blocks-and-sections.md](../05-Rendering/blocks-and-sections.md) · [membership.md](membership.md) · [../07-API/payments.md](../07-API/payments.md)
