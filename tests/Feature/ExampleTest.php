<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * '/' is a landing redirect into the library. For a guest that means a
     * 302 towards /references (and then /login), not a 200 welcome page.
     */
    public function test_the_root_redirects_to_the_library(): void
    {
        $this->get('/')->assertRedirect(route('references.index', absolute: false));
    }
}
