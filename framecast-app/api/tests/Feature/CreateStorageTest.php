<?php

namespace Tests\Feature;

use App\Services\Create\{CreateStorage, PrivateBucketGuard};
use Illuminate\Support\Facades\{DB, Http, Storage};
use Tests\TestCase;

class CreateStorageTest extends TestCase
{
    private CreateStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_storage_test', 'database.connections.create_storage_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'create.storage_disk' => 'create_private']);
        DB::purge('create_storage_test');
        Http::preventStrayRequests();
        (require database_path('migrations/2026_10_06_230000_create_create_stored_files.php'))->up();
        Storage::fake('local');
        Storage::fake('create_private');
        $guard = \Mockery::mock(PrivateBucketGuard::class);
        $guard->shouldReceive('assertPrivate')->with('create_private');
        $this->app->instance(PrivateBucketGuard::class, $guard);
        $this->storage = app(CreateStorage::class);
    }

    public function test_remote_write_is_verified_and_reachable_on_a_host_without_local_originals(): void
    {
        $path = 'create/uploads/7/a/image.png';
        $this->assertTrue($this->storage->put($path, 'private-image'));
        $row = DB::table('create_stored_files')->first();
        $this->assertSame('create_private', $row->disk);
        $this->assertSame(hash('sha256', 'private-image'), $row->sha256);
        $this->assertSame(13, (int) $row->bytes);
        $this->assertSame('private', Storage::disk('create_private')->getVisibility($row->object_key));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('private-image', $this->storage->get($path));
        // A fresh API/planning host has the same catalog but no local files or cache.
        Storage::fake('local');
        $fresh = new CreateStorage(app(PrivateBucketGuard::class));
        $stream = $fresh->readStream($path);
        try { $this->assertSame('private-image', stream_get_contents($stream)); } finally { fclose($stream); }
        $this->assertSame([$path], $fresh->allFiles('create/uploads/7'));
    }

    public function test_migration_preserves_original_and_verifies_again_on_resume(): void
    {
        $path = 'create/previews/run/output.mp4';
        Storage::disk('local')->put($path, 'original-video');
        $this->assertSame('original-video', $this->storage->get($path));
        $this->assertSame('copied', $this->storage->migrate($path));
        $this->assertSame('original-video', Storage::disk('local')->get($path));
        $this->assertSame('verified', $this->storage->migrate($path));
        Storage::disk('local')->delete($path);
        $this->assertSame('original-video', $this->storage->get($path));
    }

    public function test_missing_remote_object_does_not_fall_back_to_local_original(): void
    {
        $path = 'create/previews/run/output.mp4';
        Storage::disk('local')->put($path, 'old');
        $this->storage->migrate($path);
        Storage::disk('create_private')->delete(DB::table('create_stored_files')->value('object_key'));
        $this->assertFalse($this->storage->exists($path));
        $this->assertNull($this->storage->get($path));
        Storage::disk('local')->assertExists($path);
    }

    public function test_corrupt_remote_bytes_are_rejected_before_delivery(): void
    {
        $path = 'create/inputs/7/input.png';
        $this->storage->put($path, 'approved');
        Storage::disk('create_private')->put(DB::table('create_stored_files')->value('object_key'), 'tampered');
        $this->expectExceptionMessage('integrity check');
        $this->storage->path($path);
    }

    public function test_a_corrupt_cache_is_replaced_with_verified_bytes(): void
    {
        $path = 'create/inputs/7/input.png';
        $this->storage->put($path, 'approved');
        $cached = $this->storage->path($path);
        file_put_contents($cached, 'tampered');
        $this->assertSame('approved', $this->storage->get($path));
    }

    public function test_tombstone_prevents_a_retained_local_copy_from_reappearing(): void
    {
        $path = 'create/inputs/7/old.png';
        Storage::disk('local')->put($path, 'old');
        $this->storage->migrate($path);
        $this->assertTrue($this->storage->delete($path));
        $this->assertFalse($this->storage->exists($path));
        $this->assertSame([], $this->storage->allFiles('create/inputs/7'));
        $this->assertSame('deleted', $this->storage->migrate($path));
        Storage::disk('local')->assertExists($path);
        $this->assertCount(0, Storage::disk('create_private')->allFiles());
    }

    public function test_unverified_upload_never_changes_the_authoritative_location(): void
    {
        $path = 'create/previews/run/output.mp4';
        Storage::disk('local')->put($path, 'original');
        $broken = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $broken->shouldReceive('put')->once()->andReturn(true);
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, 'wrong'); rewind($stream);
        $broken->shouldReceive('readStream')->once()->andReturn($stream);
        Storage::set('create_private', $broken);
        try { $this->storage->migrate($path); $this->fail('Corruption was accepted.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('failed verification', $e->getMessage()); }
        $this->assertSame(0, DB::table('create_stored_files')->count());
        $this->assertSame('original', $this->storage->get($path));
    }

    public function test_bucket_privacy_failure_blocks_upload_before_any_bytes_are_sent(): void
    {
        $guard = \Mockery::mock(PrivateBucketGuard::class);
        $guard->shouldReceive('assertPrivate')->once()->andThrow(new \RuntimeException('Not private'));
        try { (new CreateStorage($guard))->put('create/uploads/7/image.png', 'private'); $this->fail('Upload was allowed.'); }
        catch (\RuntimeException $e) { $this->assertSame('Not private', $e->getMessage()); }
        $this->assertSame([], Storage::disk('create_private')->allFiles());
        $this->assertSame(0, DB::table('create_stored_files')->count());
    }

    public function test_invalid_paths_cannot_access_other_application_files(): void
    {
        foreach (['../secret', 'create/inputs/../secret', 'create/inputs//x', 'create/object-cache/x', 'private/x', 'create/inputs/a\\x'] as $path) {
            try { $this->storage->get($path); $this->fail('Invalid path was accepted: '.$path); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_directory_listing_does_not_treat_underscore_as_a_wildcard(): void
    {
        $this->storage->put('create/references/a_b/sheet.jpg', 'first');
        $this->storage->put('create/references/axb/sheet.jpg', 'second');
        $this->assertSame(['create/references/a_b/sheet.jpg'], $this->storage->allFiles('create/references/a_b'));
    }

    public function test_migration_inventory_is_dry_by_default_and_batches_resume(): void
    {
        Storage::disk('local')->put('create/uploads/7/a.png', 'a');
        Storage::disk('local')->put('create/uploads/7/b.png', 'b');
        Storage::disk('local')->put('create/planner-inspections/temporary', 'scratch');
        $this->artisan('create:migrate-storage', ['--limit' => 1])->expectsOutputToContain('inventory create/uploads/7/a.png')->assertSuccessful();
        $this->assertSame([], Storage::disk('create_private')->allFiles());
        $this->artisan('create:migrate-storage', ['--apply' => true, '--limit' => 1])->assertSuccessful();
        $this->artisan('create:migrate-storage', ['--apply' => true, '--after' => 'create/uploads/7/a.png'])->assertSuccessful();
        $this->assertSame(2, DB::table('create_stored_files')->count());
        Storage::disk('local')->assertExists(['create/uploads/7/a.png', 'create/uploads/7/b.png', 'create/planner-inspections/temporary']);
    }

    public function test_real_bucket_guard_accepts_only_owner_grants_and_rejects_public_access(): void
    {
        config(['filesystems.disks.create_private' => ['driver' => 's3', 'bucket' => 'private-test',
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com', 'visibility' => 'private']]);
        $client = \Mockery::mock();
        $owner = ['Grantee' => ['Type' => 'CanonicalUser', 'ID' => 'owner'], 'Permission' => 'FULL_CONTROL'];
        $client->shouldReceive('getBucketAcl')->with(['Bucket' => 'private-test'])->twice()->andReturn(
            ['Owner' => ['ID' => 'owner'], 'Grants' => [$owner]],
            ['Owner' => ['ID' => 'owner'], 'Grants' => [$owner, ['Grantee' => ['Type' => 'Group', 'URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'], 'Permission' => 'READ']]],
        );
        $adapter = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $adapter->shouldReceive('getClient')->twice()->andReturn($client);
        Storage::set('create_private', $adapter);
        $guard = new PrivateBucketGuard();
        $guard->assertPrivate('create_private');
        $guard->assertPrivate('create_private'); // Request-scoped cache avoids another ACL request.
        $this->expectExceptionMessage('beyond its owner');
        (new PrivateBucketGuard())->assertPrivate('create_private');
    }

    public function test_privacy_guard_rejects_public_aliases_and_unverified_s3_endpoints(): void
    {
        foreach (['b2', 'minio', 'local'] as $disk) {
            try { (new PrivateBucketGuard())->assertPrivate($disk); $this->fail('Non-dedicated disk accepted.'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('dedicated', $e->getMessage()); }
        }
        config(['filesystems.disks.create_private' => ['driver' => 's3', 'bucket' => 'example',
            'endpoint' => 'https://s3.amazonaws.com', 'visibility' => 'private']]);
        $this->expectExceptionMessage('not configured');
        (new PrivateBucketGuard())->assertPrivate('create_private');
    }

    public function test_remote_writes_require_the_catalog_migration(): void
    {
        \Illuminate\Support\Facades\Schema::drop('create_stored_files');
        $this->expectExceptionMessage('storage migration');
        $this->storage->put('create/uploads/7/a.png', 'private');
    }

    private function releaseLocalUse(): void
    {
        $this->app->forgetInstance(\App\Services\Create\LocalStorageUse::class);
    }

    public function test_cleanup_skips_active_readers_then_removes_only_old_cache_files(): void
    {
        $this->storage->put('create/inputs/7/a.png', 'cached');
        $cache = $this->storage->path('create/inputs/7/a.png');
        touch($cache, time() - 90000);
        $local = Storage::disk('local');
        $local->put('create/object-cache/manual-note.txt', 'keep');
        $local->put('create/planner-inspections/draft.txt', 'keep scratch');
        $this->assertTrue($this->storage->maintainLocal(true)['busy']);
        $this->assertFileExists($cache);
        $this->releaseLocalUse();
        $dry = $this->storage->maintainLocal();
        $this->assertSame(1, $dry['candidates']); $this->assertSame(0, $dry['removed_files']);
        $this->assertFileExists($cache);
        $report = $this->storage->maintainLocal(true);
        $this->assertSame(1, $report['removed_files']); $this->assertSame(6, $report['removed_bytes']);
        $this->assertFileDoesNotExist($cache);
        $local->assertExists(['create/object-cache/manual-note.txt', 'create/planner-inspections/draft.txt']);
        $this->assertSame('cached', $this->storage->get('create/inputs/7/a.png'), 'Remote original survives cache eviction.');
    }

    public function test_reclaim_verifies_remote_and_local_bytes_preserving_uncertain_and_uncatalogued_sources(): void
    {
        foreach (['good', 'remote-bad', 'local-bad'] as $name) {
            $path = 'create/previews/run/'.$name.'.mp4';
            Storage::disk('local')->put($path, $name);
            $this->storage->migrate($path);
        }
        $bad = DB::table('create_stored_files')->where('path', 'like', '%/remote-bad.mp4')->value('object_key');
        Storage::disk('create_private')->put($bad, 'corrupt');
        Storage::disk('local')->put('create/previews/run/local-bad.mp4', 'changed');
        Storage::disk('local')->put('create/previews/run/uncatalogued.mp4', 'only copy');
        $this->releaseLocalUse();
        $this->assertSame(3, $this->storage->maintainLocal(false, 100, true)['candidates']);
        $report = $this->storage->maintainLocal(true, 100, true);
        $this->assertSame(1, $report['removed_files']); $this->assertSame(2, $report['errors']);
        Storage::disk('local')->assertMissing('create/previews/run/good.mp4');
        Storage::disk('local')->assertExists(['create/previews/run/remote-bad.mp4', 'create/previews/run/local-bad.mp4', 'create/previews/run/uncatalogued.mp4']);
        $this->assertSame(3, DB::table('create_stored_files')->whereNull('deleted_at')->count());
        $this->assertSame('good', $this->storage->get('create/previews/run/good.mp4'));
    }

    public function test_cache_cleanup_is_bounded_and_never_follows_symlinks(): void
    {
        $local = Storage::disk('local');
        foreach (['a', 'b'] as $name) {
            $path = 'create/object-cache/'.str_repeat($name, 64).'.png';
            $local->put($path, 'old'); touch($local->path($path), time() - 90000);
        }
        $local->put('create/uploads/7/only-copy.png', 'keep');
        symlink($local->path('create/uploads/7/only-copy.png'), $local->path('create/object-cache/'.str_repeat('c', 64).'.png'));
        $this->assertSame(1, $this->storage->maintainLocal(true, 1)['removed_files']);
        $this->assertSame(1, $this->storage->maintainLocal(true, 1)['removed_files']);
        $this->assertSame(0, $this->storage->maintainLocal(true)['removed_files']);
        $local->assertExists('create/uploads/7/only-copy.png');
    }

    public function test_low_disk_rejects_remote_write_before_upload_and_does_not_block_existing_reads(): void
    {
        $this->storage->put('create/uploads/7/old.png', 'old');
        $this->storage->get('create/uploads/7/old.png'); // Cached before capacity ran low.
        $capacity = \Mockery::mock(\App\Services\Create\DiskSpace::class);
        $capacity->shouldReceive('requireSpace')->andThrow(new \App\Services\Create\DiskCapacityException());
        $this->app->instance(\App\Services\Create\DiskSpace::class, $capacity);
        try { $this->storage->put('create/uploads/7/new.png', 'new'); $this->fail('Write accepted with no headroom.'); }
        catch (\App\Services\Create\DiskCapacityException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $this->assertSame(1, DB::table('create_stored_files')->count());
        $this->assertSame('old', $this->storage->get('create/uploads/7/old.png'));
    }

    public function test_maintenance_command_is_dry_by_default(): void
    {
        $path = 'create/object-cache/'.str_repeat('a', 64).'.png';
        Storage::disk('local')->put($path, 'old'); touch(Storage::disk('local')->path($path), time() - 90000);
        $this->artisan('create:maintain-storage')->expectsOutputToContain('"apply": false')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->artisan('create:maintain-storage', ['--apply' => true])->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
    }
}
