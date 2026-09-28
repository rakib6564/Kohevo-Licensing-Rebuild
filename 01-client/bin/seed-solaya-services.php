<?php
/**
 * Solaya — seed services, provider and the Corps & Âme plan from the
 * owner's confirmed service list (Sept 2026).
 *
 * Differs from bin/seed-solaya.php in that it carries real durations and
 * prices. IDEMPOTENT: rows are matched on slug/name; prices, deposits and
 * the plan price are written on INSERT only, so a re-run never overwrites
 * figures edited later in the admin.
 *
 * Run:  php bin/seed-solaya-services.php --dry-run
 *       php bin/seed-solaya-services.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$DRY = in_array('--dry-run', array_slice($argv, 1), true);
$tid = current_tenant_id();
$todo = [];
function say(string $s): void { echo $s . "\n"; }

say($DRY ? "DRY RUN — nothing will be written\n" : "Seeding Solaya services\n");

// ── Provider ─────────────────────────────────────────────────
$providerName = 'Stéphanie Giambertone';
$provider = Database::row("SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?", [$tid, $providerName]);
if ($provider) {
    $providerId = (int) $provider['id'];
    say("  = provider  $providerName (#$providerId)");
} elseif ($DRY) {
    $providerId = 0;
    say("  + provider  $providerName");
} else {
    Database::query(
        "INSERT INTO booking_providers (tenant_id, name, timezone, is_active, sort_order) VALUES (?, ?, 'Europe/Paris', 1, 0)",
        [$tid, $providerName]
    );
    $providerId = (int) Database::value("SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?", [$tid, $providerName]);
    say("  + provider  $providerName (#$providerId)");
}
$todo[] = "Set $providerName's weekly hours in Booking → Providers. None were provided, so NO slots are bookable yet.";

// ── Services ─────────────────────────────────────────────────
// price_cents / deposit_* are in cents.
$services = [
    [
        'slug' => 'discovery-call', 'order' => 1, 'online' => 1,
        'name' => 'Discovery Call', 'name_fr' => 'Entretien Découverte',
        'desc' => 'A free introductory call to get acquainted, clarify your needs and expectations, and determine the most suitable Solaya program for you.',
        'desc_fr' => "Un appel d'introduction gratuit pour faire connaissance, clarifier vos besoins et vos attentes, et déterminer le programme Solaya qui vous convient le mieux.",
        'dur' => 20, 'dur_max' => 30, 'price' => 0, 'pay' => 'free', 'dep_type' => 'percent', 'dep_val' => 0,
    ],
    [
        'slug' => 'bilan-nutrition', 'order' => 2, 'online' => 0,
        'name' => 'Nutrition Assessment', 'name_fr' => 'Bilan Nutrition Pleine Santé',
        'desc' => 'A comprehensive one-hour nutrition assessment considering your habits, rhythm, stress, and relationship with your body. Includes a 7-day pre-session diary and a personalized written summary.',
        'desc_fr' => "Un bilan nutritionnel complet d'une heure, prenant en compte vos habitudes, votre rythme, votre stress et votre rapport à votre corps. Comprend un carnet alimentaire de 7 jours avant la séance et une synthèse écrite personnalisée.",
        'dur' => 60, 'dur_max' => null, 'price' => 7500, 'pay' => 'full', 'dep_type' => 'percent', 'dep_val' => 0,
    ],
    [
        'slug' => 'hypnose-serenite', 'order' => 3, 'online' => 0,
        'name' => 'Emotional Serenity Hypnosis', 'name_fr' => 'Hypnose Sérénité Émotionnelle',
        'desc' => 'A targeted hypnosis session designed to regulate emotions, release tension, ease stress, and activate internal resources in a gentle, conscious, and safe space. Flat rate.',
        'desc_fr' => "Une séance d'hypnose ciblée pour réguler les émotions, relâcher les tensions, apaiser le stress et activer vos ressources internes dans un espace doux, conscient et sécurisant. Tarif forfaitaire.",
        'dur' => 60, 'dur_max' => 90, 'price' => 7000, 'pay' => 'full', 'dep_type' => 'percent', 'dep_val' => 0,
    ],
    [
        'slug' => 'hsr', 'order' => 4, 'online' => 0,
        'name' => 'Spiritual Regressive Hypnosis', 'name_fr' => 'Hypnose Spirituelle Régressive',
        'desc' => "An in-depth spiritual exploration in an expanded state of consciousness to uncover soul memories, past lives, or life-between-lives insights to release recurring blockages. Includes preparation and a post-session audio recording.\n\nRate: €70 per hour, capped at €210. Typical length 2 to 3 hours (up to 3h30–4h can be planned). Requires a prior Discovery Call and booking at least 3 weeks in advance. The first hour is paid at booking; the balance is settled after the session.",
        'desc_fr' => "Une exploration spirituelle approfondie dans un état de conscience élargi pour retrouver des mémoires d'âme, des vies passées ou des enseignements de l'entre-deux-vies et libérer des blocages récurrents. Comprend la préparation et un enregistrement audio après la séance.\n\nTarif : 70 € de l'heure, plafonné à 210 €. Durée habituelle de 2 à 3 heures (planifiable jusqu'à 3h30–4h). Nécessite un Entretien Découverte préalable et une réservation au moins 3 semaines à l'avance. La première heure est réglée à la réservation ; le solde est réglé après la séance.",
        'dur' => 120, 'dur_max' => 240, 'price' => 21000, 'pay' => 'deposit', 'dep_type' => 'fixed', 'dep_val' => 7000,
    ],
    [
        'slug' => 'suivi-corps-ame', 'order' => 5, 'online' => 1,
        'name' => 'Body & Soul Follow-up', 'name_fr' => 'Suivi Parcours Essentiel',
        'desc' => 'Regular bi-weekly follow-up or guided practice session (breathwork, meditation, or guidance) reserved for clients enrolled in the 3-month Essential Program. Included in the membership.',
        'desc_fr' => "Suivi régulier toutes les deux semaines ou séance de pratique guidée (respiration, méditation ou accompagnement) réservé aux clientes inscrites au Parcours Essentiel de 3 mois. Inclus dans l'abonnement.",
        'dur' => 30, 'dur_max' => 45, 'price' => 0, 'pay' => 'free', 'dep_type' => 'percent', 'dep_val' => 0,
    ],
];

$ids = [];
foreach ($services as $s) {
    $row = Database::row("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = ?", [$tid, $s['slug']]);
    $label = sprintf("%-16s %3d–%-3s min  %s", $s['slug'], $s['dur'], $s['dur_max'] ?? '-', number_format($s['price'] / 100, 2) . ' EUR');
    if ($row) {
        $ids[$s['slug']] = (int) $row['id'];
        if (!$DRY) {
            Database::update('booking_services', [
                'name' => $s['name'], 'name_fr' => $s['name_fr'],
                'description' => $s['desc'], 'description_fr' => $s['desc_fr'],
                'duration_min' => $s['dur'], 'duration_max_min' => $s['dur_max'],
                'is_online' => $s['online'], 'sort_order' => $s['order'], 'is_active' => 1,
            ], 'id = ?', [$row['id']]);
        }
        say("  = service   $label");
    } elseif ($DRY) {
        $ids[$s['slug']] = 0;
        say("  + service   $label  [{$s['pay']}]");
    } else {
        Database::query(
            "INSERT INTO booking_services
               (tenant_id, name, name_fr, slug, description, description_fr, duration_min, duration_max_min,
                payment_mode, deposit_type, deposit_value, price_cents, currency, is_online, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'EUR',?,?,1)",
            [$tid, $s['name'], $s['name_fr'], $s['slug'], $s['desc'], $s['desc_fr'], $s['dur'], $s['dur_max'],
             $s['pay'], $s['dep_type'], $s['dep_val'], $s['price'], $s['online'], $s['order']]
        );
        $ids[$s['slug']] = (int) Database::value("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = ?", [$tid, $s['slug']]);
        say("  + service   $label  [{$s['pay']}]");
    }

    if ($providerId > 0 && !$DRY && $ids[$s['slug']] > 0) {
        Database::query("INSERT IGNORE INTO booking_provider_services (provider_id, service_id) VALUES (?, ?)", [$providerId, $ids[$s['slug']]]);
    }
}

// ── HSR rules: 3-week minimum advance + Discovery Call prerequisite ──
if (!empty($ids['hsr'])) {
    BookingPlusAPI::ensureSchema();
    BookingPlusAPI::saveServiceConfig((int) $ids['hsr'], [
        'min_advance_days'        => 21,
        'prereq_service_id'       => $ids['discovery-call'] ?: null,
        'prereq_message'          => "L'Hypnose Spirituelle Régressive demande un Entretien Découverte préalable — réservons d'abord ce premier échange, sans frais.\n\nSpiritual Regressive Hypnosis requires a prior Discovery Call — let's book that free first conversation.",
        'hsr_redirect_service_id' => $ids['discovery-call'] ?: null,
        'zoom_mode'               => 'fallback_message',
    ]);
}
say("  + rules     hsr: 21-day minimum advance, Discovery Call prerequisite + redirect");

// ── Membership plan: Essential Program, €99/month × 3 months ──
$planName = 'Essential Program (3 months)';
$planTotal = 9900 * 3;
$planData = [
    'name' => $planName, 'name_fr' => 'Parcours Essentiel (3 mois)',
    'description' => 'The 3-month Essential Program: €99 per month for 3 months (€297 in total). Includes the bi-weekly Body & Soul follow-up sessions and access to the programme.',
    'description_fr' => "Le Parcours Essentiel de 3 mois : 99 € par mois pendant 3 mois (297 € au total). Comprend les séances de suivi Corps & Âme toutes les deux semaines et l'accès au programme.",
    'plan_type' => 'course', 'course_id' => ($ids['suivi-corps-ame'] ?? 0) ?: null,
    'duration_days' => 90, 'currency' => 'EUR', 'is_active' => 1,
];
$plan = Database::row("SELECT id FROM membership_plans WHERE tenant_id = ? AND name = ?", [$tid, $planName]);
if ($plan) {
    if (!$DRY) Database::update('membership_plans', $planData, 'id = ?', [$plan['id']]);
    say("  = plan      $planName");
} elseif ($DRY) {
    say("  + plan      $planName  (90 days, 297.00 EUR total, gates Suivi)");
} else {
    Database::query(
        "INSERT INTO membership_plans
           (tenant_id, name, name_fr, description, description_fr, plan_type, course_id, duration_days, currency, price_cents, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)",
        [$tid, $planName, $planData['name_fr'], $planData['description'], $planData['description_fr'],
         'course', $planData['course_id'], 90, 'EUR', $planTotal]
    );
    say("  + plan      $planName  (90 days, 297.00 EUR total, gates Suivi)");
}

// ── Settings (same as seed-solaya.php) ───────────────────────
if (!$DRY) {
    Database::setSetting('membership.require_membership_to_book', '1');
    Database::setSetting('membership.require_profile_to_book', '0');
    Database::setSetting('booking.reminder_leads', '11520,1440,10');
    Database::setSetting('booking.self_cancel_enabled', '1');
    Database::setSetting('booking.self_reschedule_enabled', '1');
    Database::setSetting('booking.followup_enabled', '1');
}
say("  + settings  Suivi members-only; reminders 8d / 1d / 10min; self-service cancel + reschedule");

say("\n" . ($DRY ? "Nothing was written." : "Seed complete."));
$todo[] = "HSR is stored at its €210 cap with a €70 first-hour deposit; the system prices per booking, not per hour, so adjust the balance to €70 × hours on the day.";
$todo[] = "The plan is stored as one 90-day plan at €297 total (€99 × 3). Monthly instalments are not modelled by the membership plugin — say so if you want €99 recorded instead.";
$todo[] = "Session quota for the plan was not given (bi-weekly ≈ 6 sessions); left unset.";
$todo[] = "Add the HSR preparation-page URL and WhatsApp link in Booking → Service rules → HSR.";
say("\nStill needs a human:\n");
foreach ($todo as $i => $t) say(sprintf("  %d. %s", $i + 1, $t));
say("");
