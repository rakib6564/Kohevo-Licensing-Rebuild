# Coaching — Phase 2 design spec

The "downloaded app" experience for clients enrolled in the 3-month
Body & Soul Program (Level 2 in the client's requirements PDF).

Where Phase 1 (Booking+) handles everything **before** engagement —
appointments, payments, reminders, prep pages — Phase 2 owns the
**daily tracking + coaching relationship** during the program.

This document is the map. Read it once before writing any code.

---

## 1. What the client wants (compressed)

- **Profile**: age, height, weight, body measurements, body type,
  intolerances, dietary preferences, medical section. BMI, BMR, TDEE
  auto-computed and hidden by default.
- **Food diary**: meals + times + durations, photos, quantities,
  hydration (liters or glasses), other drinks, physical activity,
  emotions, hunger before / satiety after, context (home/work/friends).
- **Emotion tracking**: at each meal/moment — chosen from a fixed
  list. App aggregates: dominant emotion per day/week/month, and
  correlations (emotion → snacking, emotion → food type, emotion →
  hunger/satiety).
- **Automatic charts**: hydration (day/week/month), food-group
  distribution (fruits/vegetables/starches/proteins/dairy/fats/pleasure),
  physical activity vs goals, emotions.
- **Goals**: 3 levels — not achieved / partially / **exceeded** — with
  an "additional actions" free-text field. Progress curve over time.
- **Meal structure / shopping list / recipes**: three optional
  modules the practitioner can enable per-client. Recipes are
  bidirectional (client can share too).
- **Internal library** for the practitioner: reusable templates
  (typical meal structures, recipes, shopping lists) copied to a
  client and edited without affecting the original.
- **1:1 chat**: replaces WhatsApp inside the program. Photos, docs,
  video links, scheduled messages, dedicated feed per client.
- **Motivation module**: challenges, exercise requests, encouragement
  messages, scheduled notifications.
- **End of program**: automatic 3-month summary — progress,
  successes, final recommendations, thank-you message.
- **Access lifecycle**: activated by practitioner at program start,
  auto-deactivated at end but reactivatable within 1 year (data
  retained). Auto-delete after 1 year of inactivity.

---

## 2. What Slate already gives us

| Feature | Existing plugin |
|---|---|
| 3-month fixed-term program with activation / expiry / grace / 1-year retention | [membership](../plugins/membership) — `membership_plans` with `duration_days`, `grace_days`; per-member subscription rows |
| Payment (initial + monthly renewals) | [stripe-payment](../plugins/stripe-payment) via `MembershipAPI::createCheckout()` |
| Customer identity + login | Core `customers` + `Auth::attemptCustomerLogin()` |
| Customer-facing dashboard | `customer_dashboard_widgets` filter (used by booking + membership) |
| Public routes | `public_routes` filter |
| Media uploads (photos, documents) | Core Media library (`SlateMedia`, `Media::enqueuePicker()`) |
| Forms (interactive intake docs) | [forms](../plugins/forms) plugin — reuse for the initial profile capture wizard |
| Booking follow-up sessions | [booking](../plugins/booking) — the "Body & Soul Program Follow-up" service we set up in Phase 1 |

**Membership carries the lifecycle.** A new membership plan
"Body & Soul Program — 3 months" acts as the toggle for "app
downloaded" access. Coaching's admin + customer pages gate on
`MembershipAPI::isActive($customerId)`. That gives us
activation-by-practitioner, expiry, 1-year data retention with
optional reactivation — for free.

---

## 3. What Coaching adds (this plugin)

A new plugin `coaching`. Slug rules give class name `Coaching`.

### 3.1 Tables (namespaced `coaching_*`)

**Profile** (extends core customers, one row per customer):

- `coaching_profile` — dob, height_cm, weight_kg, body_measurements
  (JSON: chest, waist, hips, thighs, arms), body_type, intolerances
  (JSON array), dietary_preferences (JSON: vegetarian, vegan,
  gluten_free, lactose_free, notes, time_constraints), pathologies,
  ongoing_care, alternative_medicine, personal_issues, therapist_contact.
- `coaching_profile_computed` — bmi, bmr, tdee (recomputed on save).
  Hidden by default, exposed via a toggle.

**Food diary + tracking:**

- `coaching_diary_entry` — one row per meal / snack / moment. Fields:
  customer_id, entered_at, meal_type (breakfast|lunch|dinner|snack|
  binge|other), started_at, duration_min, quantity_note, context
  (home|work|friends|other|freetext), hunger_before (1-5), satiety_after
  (1-5), emotion (enum), emotion_note, ate_within_30min_before_emotion
  (bool — supports emotion→food correlations), notes.
- `coaching_diary_food` — line items belonging to a diary entry.
  Free-text food name + classified category
  (fruits_vegetables|starches|proteins|dairy|fats|pleasure|other) +
  is_pleasure_food, is_balanced_meal flags. Classification workflow
  in §4.2.
- `coaching_diary_photo` — many-to-one on `coaching_diary_entry`.
  Media library asset id.
- `coaching_hydration` — customer_id, day, liters, glass_count.
  Daily rollup, one row per (customer_id, day).
- `coaching_activity` — customer_id, day, kind (walk|yoga|cardio|
  strength|other|freetext), duration_min, notes.

**Goals:**

- `coaching_goal` — customer_id, scope (daily|weekly|monthly|general|
  personal), title, description, target_count, sort_order, is_active,
  created_at, retired_at. Author is the practitioner; client sees + tracks.
- `coaching_goal_checkin` — customer_id, goal_id, day, status
  (not_achieved|partial|achieved|exceeded), note.
- `coaching_extra_action` — customer_id, day, action_text. The
  "additional actions" field per §Goal Tracker in the requirements.

**Meal structure / shopping / recipes** (each optional per client —
`is_enabled` flag on the profile controls visibility):

- `coaching_meal_structure` — customer_id, day_of_week (nullable — null
  = every day), meal_type, notes_html.
- `coaching_shopping_list` — customer_id, name, sections_json (arrays
  of "Staples", "To favor", "Alternatives", "Avoid").
- `coaching_recipe` — author (practitioner or customer_id), title,
  photo_media_id, ingredients_json, instructions_html, video_url,
  visibility (practitioner|customer|library), notes.
- `coaching_recipe_share` — recipe_id → customer_id. Which shared
  recipes each client can see.

**Library** (practitioner-owned templates):

- Reuse the existing tables above with an `owner_customer_id = NULL`
  marker to mean "practitioner library". "Copy to client" inserts a
  new row with the client's id. Keeps schema simple.

**Chat:**

- `coaching_thread` — one per active client. customer_id (unique).
- `coaching_message` — thread_id, sender (practitioner|customer),
  body, media_json (photos, docs, video links), send_at (nullable
  scheduled send), sent_at, seen_at. See §4.3 for scheduling engine.

**Motivation:**

- `coaching_challenge` — customer_id, title, description, video_url,
  starts_at, ends_at, kind (challenge|exercise|encouragement).

**End of program:**

- `coaching_summary` — customer_id, generated_at, summary_json
  (successes, key metrics, recommendation text). Materialized so the
  client can revisit after program end.

**Access:**

- No new table — gate on `MembershipAPI::isActive($customerId)` +
  a `coaching.program_plan_id` setting pointing at the correct
  membership plan.

### 3.2 Auto-computed metrics

Recomputed and stored in `coaching_profile_computed` whenever
`coaching_profile` is updated:

- BMI = weight_kg / (height_m ^ 2)
- BMR (Mifflin-St Jeor):
  - female: 10·w + 6.25·h_cm − 5·age − 161
  - male:   10·w + 6.25·h_cm − 5·age + 5
- TDEE = BMR · activity_factor (default 1.4 — light activity).

Hidden by default. Practitioner toggles visibility on the client via
`coaching.show_computed` setting on the profile row.

### 3.3 Hooks + integration

- `Hook::addFilter('customer_dashboard_widgets', …)` — surface a
  "Today" card on the client dashboard: today's meals, hydration
  progress, goal check-ins.
- `Hook::addFilter('customer_nav_items', …)` — add sidebar entries
  for Diary / Goals / Chat / Recipes / Library.
- `Hook::addAction('customer_registered', …)` — auto-provision an
  empty `coaching_profile` + `coaching_thread`.
- `Hook::addAction('frequent_cron', …)` — engine for scheduled
  chat messages, reminder pings, end-of-program summary generation.
- `Hook::addFilter('membership_expired', …)` — start the 1-year
  retention timer.

---

## 4. Three sub-projects the whole plugin hinges on

Everything else is CRUD + charts. These three are load-bearing and
deserve careful design before we cut code.

### 4.1 Emotion → correlations engine

The requirements ask for three correlation views:

- **Emotion → snacking** ("70% of snacks after stress")
- **Emotion → food type** ("stress → 60% pleasure foods")
- **Emotion → hunger/satiety** ("boredom → 50% of meals without hunger")

Data lives in `coaching_diary_entry` + `coaching_diary_food`. The
correlations are just SQL group-bys — no ML needed:

```sql
-- Emotion → % of pleasure food consumption
SELECT emotion,
       SUM(CASE WHEN f.is_pleasure_food THEN 1 ELSE 0 END)
       / COUNT(*) AS pleasure_ratio
  FROM coaching_diary_entry e
  JOIN coaching_diary_food  f ON f.entry_id = e.id
 WHERE customer_id = ? AND entered_at >= ?
 GROUP BY emotion;
```

Charts rendered client-side with Chart.js (already in the stack).
Auto-messages ("You made good food choices despite the fatigue,
well done") triggered by a nightly cron scanning the day's
correlations and looking up a small pattern → message map.

### 4.2 Food classification

The requirements offer three options — my Phase 1 recommendation
was Option 2 (manual 1-click buttons). Restating for Phase 2:

- **Option 2 (MVP)** — client types a free-text food name; app
  shows quick-tap category buttons; classification stored per
  line item. Practitioner adjusts anytime.
- **Option 3 (later)** — build/license a small food database (2-3k
  entries covers most French home cooking). Suggest a category on
  autocomplete; single click validates. No AI required.
- **Option 1 (never for MVP)** — full NLP. Requires an LLM call
  per meal, latency + cost + reliability problems.

Recommend building Option 2 first, Option 3 as a Phase 2.5
enhancement once the practitioner has enough diary data to see
which patterns matter.

### 4.3 Chat with scheduled messages

Two channels of message send:

- **Live** — practitioner or client sends now; the other party sees
  a notification and reads inline.
- **Scheduled** — practitioner writes now, sets `send_at = <future
  timestamp>`, cron picks it up and delivers.

Storage: same `coaching_message` table with `send_at` nullable.
`sent_at` stamped when actually delivered. Client's inbox query is:

```sql
SELECT * FROM coaching_message
 WHERE thread_id = ? AND (send_at IS NULL OR send_at <= NOW())
 ORDER BY COALESCE(sent_at, created_at) DESC;
```

Cron every minute: `UPDATE coaching_message SET sent_at = NOW()
 WHERE sent_at IS NULL AND send_at <= NOW()`.

Notifications: reuse core `Notifications` (already on the topbar
bell) — one row per delivered message.

No WebSocket / real-time — polling every 30s from the client-side
JS on the chat page keeps it lean.

---

## 5. Implementation waves

Building the whole thing in one drop is a bad idea. Ship in waves,
each of which is a usable milestone the practitioner can pilot with
a client.

### Wave 1 — Foundation (1 week)

- Plugin scaffold, membership integration, access gate
- Client profile + auto-computed metrics
- Basic goals CRUD (practitioner-side)
- Client daily check-in stub

**Ship criterion:** practitioner activates a client's program, the
client logs in and sees an empty "Today" card with their profile.

### Wave 2 — Food diary + hydration + emotions (1.5 weeks)

- Diary entry: meal, foods (manual classify), hydration, activity,
  emotion, hunger/satiety, context
- Photo attachment via core Media
- Daily hydration widget on dashboard
- Notification to practitioner on each entry
- Practitioner inbox: today's entries across clients, click into any

**Ship criterion:** a client can complete a full daily entry;
practitioner sees it live and can leave a comment.

### Wave 3 — Goals & charts (1 week)

- Goal check-in with 3 levels + additional actions
- Hydration chart (line)
- Food-distribution chart (stacked pie — daily/weekly/monthly)
- Emotion pie + correlation stacked bars
- Goal progress chart (line: achieved vs partial vs exceeded)

**Ship criterion:** client's dashboard shows all four charts and
updates as they log.

### Wave 4 — Chat + notifications (1 week)

- `coaching_thread` + `coaching_message` schema
- Live send + inline read
- Scheduled-send admin UI
- Cron delivery + client-side polling
- Practitioner-side "8-hour nudge" (reuse Booking+ pattern)

**Ship criterion:** WhatsApp is no longer needed for
program-client comms.

### Wave 5 — Meal structure / shopping / recipes (1 week)

- Meal structure editor + client view
- Shopping list editor + client view
- Recipe library (practitioner) + client-shared recipes
- Bidirectional recipe sharing (client submits)

**Ship criterion:** the three optional modules render on the
client dashboard only when the practitioner has populated them.

### Wave 6 — Library + motivation + end-of-program (0.5 week)

- Library CRUD (uses same tables with `owner_customer_id = NULL`)
- "Copy to client" action
- Motivation module (challenges, exercises, encouragement)
- End-of-program summary generator (cron 3 days before expiry)

**Ship criterion:** the whole PDF is covered.

**Total: ~6 weeks single-developer.** Roughly matches the earlier
estimate.

---

## 6. Out of scope for Phase 2 (parked)

- Real-time chat (WebSocket) — 30s polling is fine for MVP.
- Food-database auto-classification (Option 3) — Phase 2.5.
- LLM-generated coaching messages — deferred until we have volume
  data to fine-tune.
- Native iOS/Android app — "downloaded" here means the client
  installs a PWA to their home screen. Manifest + service worker
  ships in wave 4.
- Multi-language content authoring — English only in MVP,
  matching Phase 1 conventions.

---

## 7. Files this plugin ships (Wave 1)

```
plugins/coaching/
├── plugin.json
├── install.sql          (Wave-1 tables only: profile, goal, thread)
├── uninstall.sql
├── Coaching.php         (bootstrap, membership gate, hook wiring)
├── CoachingAPI.php      (public helpers other plugins can call)
├── admin/
│   ├── index.php        (client roster + status)
│   ├── clients.php      (list all program clients)
│   ├── client.php       (per-client dashboard: profile, goals, chat)
│   └── settings.php
├── customer/
│   ├── index.php        (client "Today" dashboard)
│   ├── profile.php
│   └── goals.php
└── assets/css/
    └── coaching.css
```

Subsequent waves add: `diary.php`, `chat.php`, `recipes.php`, etc.

---

## 8. Permissions

- `coaching.view_clients`  — see the program roster
- `coaching.manage_clients` — enrol / suspend / edit profile
- `coaching.reply_chat`    — respond in-thread
- `coaching.manage_library` — CRUD on template library

Customer-side gating uses membership.isActive() — no new permission
key required for clients.
