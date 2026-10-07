<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Each test run fakes its disks in its own folder on the machine's own disk: a shared or host-mounted folder lets
     *  parallel runs wipe each other's files and does not honour the file locks Create's storage relies on. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->useStoragePath(sys_get_temp_dir().'/wyv-test-storage-'.getmypid());
    }
}
