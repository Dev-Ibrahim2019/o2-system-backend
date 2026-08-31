<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a phone number be shared by more than one customer — but only when a
 * human has explicitly decided so.
 *
 * WHAT WAS THERE BEFORE (verified against the live schema, not assumed):
 *   UNIQUE KEY customer_phones_normalized_phone_unique (normalized_phone)
 * A single-column unique. Proven blocking by a rolled-back INSERT:
 *   SQLSTATE[23000] 1062 Duplicate entry '+970599123456'
 * So `created_new_customer` / `marked_shared_number` were impossible at the
 * DB level, on top of CustomerIdentityService::assertPhonesAvailable()
 * rejecting them at the application level.
 *
 * WHY NOT JUST DROP THE UNIQUE: it is the only backstop preventing accidental
 * duplicate customers — the exact failure mode the CRM identity layer exists
 * to prevent. Dropping it to serve a rare intentional case would trade a
 * guarantee for a convenience.
 *
 * WHAT THIS DOES INSTEAD: `is_shared` marks a row as intentionally shared.
 * A STORED generated column mirrors normalized_phone for ordinary rows and
 * goes NULL for shared ones; the unique index moves onto that column. MySQL
 * permits many NULLs in a unique index, so:
 *   - is_shared = 0  → uniqueness enforced exactly as before
 *   - is_shared = 1  → exempt, by explicit decision only
 * Existing rows all default to is_shared = 0, so current behaviour is
 * unchanged — this is a strict superset.
 *
 * Soft-delete semantics are deliberately left alone: a soft-deleted phone row
 * still holds its slot, exactly as before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_phones ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified');

        DB::statement(
            'ALTER TABLE customer_phones ADD COLUMN exclusive_phone VARCHAR(20) '
            .'GENERATED ALWAYS AS (IF(is_shared = 0, normalized_phone, NULL)) STORED'
        );

        DB::statement('ALTER TABLE customer_phones DROP INDEX customer_phones_normalized_phone_unique');

        // The dropped unique was also serving every lookup in
        // CustomerResolutionService::resolve() and findByPhone(). Replace it
        // with a plain index first so resolution never regresses to a scan.
        DB::statement('CREATE INDEX customer_phones_normalized_phone_index ON customer_phones (normalized_phone)');

        DB::statement('CREATE UNIQUE INDEX customer_phones_exclusive_phone_unique ON customer_phones (exclusive_phone)');
    }

    public function down(): void
    {
        // Restoring the old single-column unique is only possible if no number
        // is actually shared; fail loudly rather than silently dropping rows.
        $shared = DB::table('customer_phones')->where('is_shared', 1)->count();
        if ($shared > 0) {
            throw new RuntimeException(
                "لا يمكن التراجع: يوجد {$shared} رقم مُعلَّم كمشترك. عالج هذه الأرقام أولًا."
            );
        }

        DB::statement('ALTER TABLE customer_phones DROP INDEX customer_phones_exclusive_phone_unique');
        DB::statement('ALTER TABLE customer_phones DROP INDEX customer_phones_normalized_phone_index');
        DB::statement('ALTER TABLE customer_phones DROP COLUMN exclusive_phone');
        DB::statement('ALTER TABLE customer_phones DROP COLUMN is_shared');
        DB::statement('CREATE UNIQUE INDEX customer_phones_normalized_phone_unique ON customer_phones (normalized_phone)');
    }
};
