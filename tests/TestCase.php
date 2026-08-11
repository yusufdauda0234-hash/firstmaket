<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Settings are memoised per request for speed, but a test process is
        // one long-lived PHP process running many "requests" against a
        // database that is reset between them. Without this, the first test to
        // read a setting would pin that value for every test after it.
        Setting::flushCache();
    }
}
