<?php
/**
 * Solaya — seed the practice configuration from the client requirements.
 *
 * Source: "Solaya — Capacités & Parcours / Capabilities & Journeys", the
 * bilingual requirements document. Everything written here is stated in that
 * document; nothing is invented. Where the document is silent — prices above
 * all — the field is left at zero and reported at the end for a human to fill
 * in. Guessing a practitioner's prices is not seeding, it is fabrication.
 *
 * IDEMPOTENT. Rows are matched on their slug/name and updated in place, so
 * re-running after an edit to this file brings the practice back in line
 * without duplicating anything. Safe to run against a live install.
 *
 * Run:  php bin/seed-solaya.php            (apply)
 *       php bin/seed-solaya.php --dry-run  (report what would change)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/seed-solaya.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$DRY = in_array('--dry-run', array_slice($argv, 1), true);
$tid = current_tenant_id();
$log = [];
$todo = [];

function say(string $s): void { echo $s . "\n"; }
function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-');
}

say($DRY ? "DRY RUN — nothing will be written\n" : "Seeding Solaya practice configuration\n");

// ── The practitioner ─────────────────────────────────────────
// §3: the Praticienne role. One provider; the document describes a
// single-practitioner practice throughout.
$providerName = 'Stéphanie Giambertone';
$provider = Database::row(
    "SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?", [$tid, $providerName]
);
if ($provider) {
    $providerId = (int) $provider['id'];
    say("  = provider  $providerName (#$providerId)");
} elseif ($DRY) {
    $providerId = 0;
    say("  + provider  $providerName");
} else {
    Database::query(
        "INSERT INTO booking_providers (tenant_id, name, timezone, is_active, sort_order)
         VALUES (?, ?, 'Europe/Paris', 1, 0)", [$tid, $providerName]
    );
    $providerId = (int) Database::value("SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?", [$tid, $providerName]);
    say("  + provider  $providerName (#$providerId)");
    $todo[] = "Set $providerName's weekly hours in Booking → Providers (none seeded — the document does not state them).";
}

// ── Appointment types ────────────────────────────────────────
// Verbatim from the Appendix: "Types de RDV, durées & paiements".
// duration_max_min carries HSR's 3h30–4h range; payment_mode maps the
// Règlement column. Prices are NOT in the document — left at 0 deliberately.
$services = [
    [
        'name'    => 'Appel Découverte / Discovery Call',
        'slug'    => 'discovery-call',
        'desc'    => "Un premier échange de 20 minutes, sans frais, pour faire connaissance et vous orienter.\n\n"
                   . "A free 20-minute introductory call to get acquainted and point you in the right direction.",
        'dur'     => 20,
        'dur_max' => null,
        'pay'     => 'free',
        'online'  => 1,
        'order'   => 1,
    ],
    [
        'name'    => 'Bilan Nutrition Pleine Santé / Nutrition Assessment',
        'slug'    => 'bilan-nutrition',
        'desc'    => "Un bilan nutritionnel complet d'une heure. Un carnet alimentaire de 7 jours vous est envoyé avant la séance.\n\n"
                   . "A full one-hour nutrition assessment. A 7-day food diary is sent to you before the session.\n\n"
                   . "Ce bilan ponctuel ne donne pas accès à l'application du programme. / This one-time assessment does not grant programme-app access.",
        'dur'     => 60,
        'dur_max' => null,
        'pay'     => 'full',
        'online'  => 0,
        'order'   => 2,
    ],
    [
        'name'    => 'Hypnose Sérénité Émotionnelle / Emotional Serenity Hypnosis',
        'slug'    => 'hypnose-serenite',
        'desc'    => "Une séance d'hypnose de deux heures centrée sur l'apaisement émotionnel.\n\n"
                   . "A two-hour hypnosis session focused on emotional calm.",
        'dur'     => 120,
        'dur_max' => null,
        'pay'     => 'full',
        'online'  => 0,
        'order'   => 3,
    ],
    [
        'name'    => 'Hypnose Spirituelle Régressive (HSR) / Spiritual Regressive Hypnosis',
        'slug'    => 'hsr',
        'desc'    => "Une séance longue de 3h30 à 4h. Un Appel Découverte préalable est obligatoire, "
                   . "et la réservation doit être faite au moins trois semaines à l'avance. "
                   . "La première heure est réglée à la réservation, le solde le jour de la séance.\n\n"
                   . "A long 3h30–4h session. A prior Discovery Call is required, and booking must be made at least three weeks ahead. "
                   . "The first hour is paid at booking, the balance on the day.",
        'dur'     => 210,
        'dur_max' => 240,
        'pay'     => 'deposit',
        'online'  => 0,
        'order'   => 4,
    ],
    [
        'name'    => 'Suivi Programme Corps & Âme / Body & Soul Follow-up',
        'slug'    => 'suivi-corps-ame',
        'desc'    => "Séance de suivi pour les clientes du Programme Corps & Âme (30 ou 45 minutes).\n\n"
                   . "Follow-up session for Body & Soul programme clients (30 or 45 minutes).",
        'dur'     => 30,
        'dur_max' => 45,
        'pay'     => 'onsite',   // "Paiement mensuel" — carried by the membership, not per booking
        'online'  => 1,
        'order'   => 5,
    ],
];

$ids = [];
foreach ($services as $s) {
    $row = Database::row("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = ?", [$tid, $s['slug']]);
    $data = [
        'name'             => $s['name'],
        'description'      => $s['desc'],
        'duration_min'     => $s['dur'],
        'duration_max_min' => $s['dur_max'],
        'payment_mode'     => $s['pay'],
        'currency'         => 'EUR',
        'is_online'        => $s['online'],
        'sort_order'       => $s['order'],
        'is_active'        => 1,
    ];
    if ($row) {
        $ids[$s['slug']] = (int) $row['id'];
        if (!$DRY) Database::update('booking_services', $data, 'id = ?', [$row['id']]);
        say("  = service   {$s['slug']}  ({$s['dur']}m, {$s['pay']})");
    } elseif ($DRY) {
        $ids[$s['slug']] = 0;
        say("  + service   {$s['slug']}  ({$s['dur']}m, {$s['pay']})");
    } else {
        Database::query(
            "INSERT INTO booking_services
               (tenant_id, name, slug, description, duration_min, duration_max_min,
                payment_mode, currency, is_online, sort_order, is_active, price_cents)
             VALUES (?,?,?,?,?,?,?,'EUR',?,?,1,0)",
            [$tid, $s['name'], $s['slug'], $s['desc'], $s['dur'], $s['dur_max'],
             $s['pay'], $s['online'], $s['order']]
        );
        $ids[$s['slug']] = (int) Database::value("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = ?", [$tid, $s['slug']]);
        say("  + service   {$s['slug']}  ({$s['dur']}m, {$s['pay']})");
    }

    if ($providerId > 0 && !$DRY && $ids[$s['slug']] > 0) {
        Database::query(
            "INSERT IGNORE INTO booking_provider_services (provider_id, service_id) VALUES (?, ?)",
            [$providerId, $ids[$s['slug']]]
        );
    }
}

$todo[] = "Set the price of each paid service in Booking → Services. "
        . "The requirements document states durations and payment modes but no amounts, "
        . "so every price is currently 0.00 EUR — including HSR, whose deposit is defined as its first hour.";

// ── HSR gating rules (§5.2) ──────────────────────────────────
// "Délai obligatoire : 3 semaines à 1 mois" — the document's own default is
// 21 days. "Prérequis : Appel Découverte obligatoire", redirecting to the
// Discovery Call rather than dead-ending on an error.
if (!$DRY && !empty($ids['hsr'])) {
    BookingPlusAPI::ensureSchema();
    BookingPlusAPI::saveServiceConfig((int) $ids['hsr'], [
        'min_advance_days'        => 21,
        'prereq_service_id'       => $ids['discovery-call'] ?: null,
        'prereq_message'          => "L'Hypnose Spirituelle Régressive demande un Appel Découverte préalable — "
                                   . "réservons d'abord ce premier échange, sans frais.\n\n"
                                   . "Spiritual Regressive Hypnosis requires a prior Discovery Call — "
                                   . "let's book that free first conversation.",
        'hsr_redirect_service_id' => $ids['discovery-call'] ?: null,
        'zoom_mode'               => 'fallback_message',
    ]);
    say("  + rules     hsr: 21-day minimum advance, Discovery Call prerequisite + redirect");
} elseif ($DRY) {
    say("  + rules     hsr: 21-day minimum advance, Discovery Call prerequisite + redirect");
}
$todo[] = "Add the HSR preparation-page URL and WhatsApp link in Booking → Service rules → HSR "
        . "(§5.2 expects a prep page and audio; the document does not give the URLs).";

// ── The programme (§1, level 2) ──────────────────────────────
// "Programme Corps & Âme (3 mois)" — a fixed-term plan gating app access,
// with FR and EN names, which the plan table supports directly.
$planName = 'Body & Soul Programme (3 months)';
$plan = Database::row("SELECT id FROM membership_plans WHERE tenant_id = ? AND name = ?", [$tid, $planName]);
$planData = [
    'name'           => $planName,
    'name_fr'        => 'Programme Corps & Âme (3 mois)',
    'description'    => "Three-month coaching programme: daily tracking, goals, 1:1 chat, meal structure, "
                      . "recipes and the end-of-programme summary. Grants access to the downloadable app.",
    'description_fr' => "Programme d'accompagnement de trois mois : suivi quotidien, objectifs, chat 1:1, "
                      . "structure alimentaire, recettes et récapitulatif de fin de programme. "
                      . "Donne accès à l'application téléchargeable.",
    'plan_type'      => 'course',
    // Attach the plan to the Suivi follow-up service. A 'course' plan's
    // course_id is what MembershipAPI::serviceRequiresMembership() reads, so
    // this is what makes Suivi — and only Suivi — members-only, matching the
    // Appendix ("Suivi Programme Corps & Âme") and §1 (programme clients).
    // Price and session_quota are deliberately not set here: they are the
    // practice's own figures, set in the admin, and the seed must not
    // overwrite them on a re-run.
    'course_id'      => ($ids['suivi-corps-ame'] ?? 0) ?: null,
    'duration_days'  => 90,
    'currency'       => 'EUR',
    'is_active'      => 1,
];
if ($plan) {
    if (!$DRY) Database::update('membership_plans', $planData, 'id = ?', [$plan['id']]);
    say("  = plan      Corps & Âme (90 days, course, gates Suivi)");
} elseif ($DRY) {
    say("  + plan      Corps & Âme (90 days, course, gates Suivi)");
} else {
    Database::query(
        "INSERT INTO membership_plans
           (tenant_id, name, name_fr, description, description_fr, plan_type, course_id, duration_days, currency, price_cents, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,0,1)",
        [$tid, $planData['name'], $planData['name_fr'], $planData['description'],
         $planData['description_fr'], 'course', $planData['course_id'], 90, 'EUR']
    );
    say("  + plan      Corps & Âme (90 days, course, gates Suivi)");
}
$todo[] = "Set the Corps & Âme programme price in Membership → Plans (currently 0.00 EUR).";

// ── The Level 1 / Level 2 boundary (§1) ──────────────────────
// §1 puts every Level 1 service — Discovery Call, Bilan Nutrition, Hypnose
// Sérénité and HSR — in front of "toutes les prospects & clientes avant un
// programme", people with no membership yet. Only the Suivi follow-up is for
// programme clients.
//
// require_membership_to_book is ON. It used to gate every booking, which
// locked prospects out entirely, so an earlier version of this seed forced it
// OFF. Membership::gateBooking() now applies it only to services a 'course'
// plan is attached to via course_id (see the plan above), so ON means exactly
// "Suivi is members-only" and nothing else. Forcing it OFF here would silently
// unlock Suivi for anyone.
//
// require_profile_to_book stays OFF: that gate is still global, and a
// prospect booking a first Discovery Call has no member profile to complete.
//
// The strict boundary the document insists on ("un Bilan Nutrition ponctuel
// ne donne JAMAIS accès à l'application") is about APP access, and coaching
// enforces it separately: CoachingAPI::hasAccess() delegates to
// MembershipAPI::isActive().
if (!$DRY) {
    Database::setSetting('membership.require_membership_to_book', '1');
    Database::setSetting('membership.require_profile_to_book', '0');
}
say("  + settings  Level 1 open to prospects; Suivi members-only (Level 2 app still membership-gated)");

// ── Reminder cadence (§ Booking+ / §5.1) ─────────────────────
// "Cadence de rappels 8 jours / veille / 10 min", in minutes before the
// appointment: 8 days = 11520, day before = 1440, 10 minutes = 10.
if (!$DRY) {
    Database::setSetting('booking.reminder_leads', '11520,1440,10');
    Database::setSetting('booking.self_cancel_enabled', '1');
    Database::setSetting('booking.self_reschedule_enabled', '1');
    Database::setSetting('booking.followup_enabled', '1');
}
say("  + settings  reminder cadence 8d / 1d / 10min; self-service cancel + reschedule on");

// ── Report ───────────────────────────────────────────────────
say("\n" . ($DRY ? "Nothing was written." : "Seed complete."));
say("\nStill needs a human — the requirements document does not state these:\n");
foreach ($todo as $i => $t) say(sprintf("  %d. %s", $i + 1, $t));
say("");
