<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION wyv_project_write_lock() RETURNS trigger AS $$
DECLARE target bigint;
BEGIN
    IF TG_TABLE_NAME = 'projects' THEN
        target := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
    ELSE
        target := CASE WHEN TG_OP = 'DELETE' THEN OLD.project_id ELSE NEW.project_id END;
    END IF;
    -- Never wait while holding a tuple lock: an editor owns the session
    -- fence and may need this same tuple. Reject instead of deadlocking.
    IF NOT pg_try_advisory_xact_lock(198734, target::integer) THEN
        RAISE EXCEPTION 'Project is being updated' USING ERRCODE = '55P03';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wyv_project_write BEFORE UPDATE OR DELETE ON projects FOR EACH ROW EXECUTE FUNCTION wyv_project_write_lock();
CREATE TRIGGER wyv_scene_write BEFORE INSERT OR UPDATE OR DELETE ON scenes FOR EACH ROW EXECUTE FUNCTION wyv_project_write_lock();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::unprepared('DROP TRIGGER IF EXISTS wyv_scene_write ON scenes; DROP TRIGGER IF EXISTS wyv_project_write ON projects; DROP FUNCTION IF EXISTS wyv_project_write_lock();');
    }
};
