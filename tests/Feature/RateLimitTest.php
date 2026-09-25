<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_code_lookup_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 20; $i++) {
            $statuses[] = $this->get('/1234')->getStatusCode();
        }

        $this->assertContains(
            429,
            $statuses,
            'Repeated code lookups must eventually be throttled (statuses: ' . implode(',', $statuses) . ')'
        );
    }

    public function test_portal_creation_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 15; $i++) {
            $statuses[] = $this->postJson('/portal/create', ['duration' => 5])->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }
}
