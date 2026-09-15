<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must not depend on `npm run build` having been run: Inertia
        // renders a Blade shell, and the @vite directive would otherwise
        // throw "Unable to locate file in Vite manifest" in a clean checkout.
        // Assets are verified separately (build + Playwright), not here.
        $this->withoutVite();
    }
}
