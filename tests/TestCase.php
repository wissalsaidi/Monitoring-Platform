<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\FakesDns;

abstract class TestCase extends BaseTestCase
{
    use FakesDns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns();
    }
}
