<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppearanceTest extends TestCase
{
    public function test_pages_default_to_dark_mode()
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('class="dark"', false);
    }

    public function test_mode_comes_from_the_cookie()
    {
        $this->withUnencryptedCookies(['appearance' => 'light'])
            ->get('/')
            ->assertDontSee('class="dark"', false);
    }
}
