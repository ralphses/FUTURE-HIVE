<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_uses_utc_as_its_storage_timezone(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
