<?php
/**
 * 0015_platform_plans — Phase 1E C1: platform-level Plans + Entitlements.
 *
 * NOT the same concept as plugins/membership's membership_plans/
 * membership_subscriptions — those are a tenant's OWN plans, sold to ITS
 * end customers (e.g. a gym's membership tiers), keyed by customer_id (see
 * plugins/membership/install.sql's own header comment). platform_plans is
 * the platform's plans, sold to TENANTS — a completely separate hierarchy,
 * hence the distinct table names.
 *
 * feature_key values in plan_entitlements correspond to real, installed
 * plugin slugs (verified live on production: booking, coaching, forms,
 * media-library, membership, multilang-translate, stripe-payment) — never
 * an invented feature. Seeded with 4 example plans + entitlement rows so
 * the new admin/plans.php and admin/licenses.php UIs have real data to
 * demonstrate against; every seeded value is a plain row, trivially edited
 * or deleted afterward through the UI — not a hardcoded constraint.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('platform_plans', function (Table $t) {
            $t->id();
            $t->string('slug', 64);
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->int('sort_order')->default(0);
            $t->json('limits')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique('slug', 'uniq_plan_slug');
        });

        $s->create('plan_entitlements', function (Table $t) {
            $t->id();
            $t->int('plan_id')->unsigned();
            $t->string('feature_key', 64);
            $t->boolean('enabled')->default(1);
            $t->datetime('created_at')->useCurrent();
            $t->unique(['plan_id', 'feature_key'], 'uniq_plan_feature');
            $t->index('feature_key', 'idx_feature_key');
        });

        $this->seed();
    }

    private function seed(): void
    {
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'sort_order' => 10,
                'description' => 'Get started with the basics.',
                'limits' => ['max_users' => 2, 'max_customers' => 50],
                'features' => ['forms']],
            ['slug' => 'starter', 'name' => 'Starter', 'sort_order' => 20,
                'description' => 'For a single small team.',
                'limits' => ['max_users' => 5, 'max_customers' => 500],
                'features' => ['forms', 'booking', 'media-library']],
            ['slug' => 'professional', 'name' => 'Professional', 'sort_order' => 30,
                'description' => 'Full feature set for a growing business.',
                'limits' => ['max_users' => 25, 'max_customers' => 5000],
                'features' => ['forms', 'booking', 'coaching', 'membership', 'media-library', 'multilang-translate']],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 40,
                'description' => 'Every feature, no limits.',
                'limits' => ['max_users' => null, 'max_customers' => null],
                'features' => ['forms', 'booking', 'coaching', 'membership', 'media-library', 'multilang-translate', 'stripe-payment']],
        ];

        foreach ($plans as $plan) {
            $planId = \Database::insert('platform_plans', [
                'slug'        => $plan['slug'],
                'name'        => $plan['name'],
                'description' => $plan['description'],
                'is_active'   => 1,
                'sort_order'  => $plan['sort_order'],
                'limits'      => json_encode($plan['limits']),
            ]);
            foreach ($plan['features'] as $feature) {
                \Database::insert('plan_entitlements', [
                    'plan_id'     => $planId,
                    'feature_key' => $feature,
                    'enabled'     => 1,
                ]);
            }
        }
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('plan_entitlements');
        $s->dropIfExists('platform_plans');
    }
};
