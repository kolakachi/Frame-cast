<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the (project_id, scene_order) unique constraint DEFERRABLE INITIALLY
 * DEFERRED so it is enforced at COMMIT, not per-row mid-statement.
 *
 * Inserting a scene in the middle shifts the rest down with
 * `UPDATE scenes SET scene_order = scene_order + 1 WHERE scene_order >= N`.
 * Postgres checks the unique constraint immediately per row, so bumping
 * 2->3 while a row at 3 still exists throws 23505 — and Postgres ignores
 * `ORDER BY` on UPDATE, so the "bump from the back" trick never worked.
 * Deferring the check lets the whole shift complete before uniqueness is
 * verified. Fixes AddSceneTool, ReorderSceneTool, and any future reorder.
 */
return new class extends Migration
{
    private string $constraint = 'scenes_project_id_scene_order_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE scenes DROP CONSTRAINT IF EXISTS {$this->constraint}");
        DB::statement("ALTER TABLE scenes ADD CONSTRAINT {$this->constraint} UNIQUE (project_id, scene_order) DEFERRABLE INITIALLY DEFERRED");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE scenes DROP CONSTRAINT IF EXISTS {$this->constraint}");
        DB::statement("ALTER TABLE scenes ADD CONSTRAINT {$this->constraint} UNIQUE (project_id, scene_order)");
    }
};
