<?php
/**
 * Slate — Migration base class.
 *
 * Versioned, ordered, reversible schema evolution (migrations doc, ADR-0010).
 * Replaces the per-request ensureSchema() self-heal — migrations run at
 * activate/upgrade/deploy, off the hot path. A migration file `return`s an
 * instance:
 *
 *   // db/migrations/0007_orders_money_to_minor_units.php
 *   return new class extends \Slate\Data\Migration {
 *       public function up(Schema $s): void {
 *           $s->table('shop_orders', fn (Table $t) => $t->bigInt('total_minor'));
 *           $s->raw('UPDATE shop_orders SET total_minor = ROUND(total * 100)');
 *       }
 *       public function down(Schema $s): void {
 *           $s->table('shop_orders', fn (Table $t) => $t->drop('total_minor'));
 *       }
 *   };
 *
 * Returning an anonymous class means no global class-name collisions across
 * hundreds of migration files. The MigrationRunner names each migration by its
 * filename and records it in the ledger.
 *
 * Layer: Data.
 */

declare(strict_types=1);

namespace Slate\Data;

use Slate\Data\Schema\Schema;

abstract class Migration
{
    /** Apply the change. */
    abstract public function up(Schema $s): void;

    /**
     * Reverse the change — a true inverse of up() (enables rollback in dev/
     * staging; production is forward-only). Default is a no-op for irreversible
     * migrations, which SHOULD document why.
     */
    public function down(Schema $s): void
    {
        // Irreversible by default; override to support rollback.
    }
}
