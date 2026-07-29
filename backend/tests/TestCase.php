<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiter hits live in the (array) cache, which persists across
        // tests within one PHPUnit process. Flush so per-IP limits (register,
        // forgot-password, ...) never leak between tests — each test starts
        // with a clean limiter window.
        Cache::flush();
    }
}
