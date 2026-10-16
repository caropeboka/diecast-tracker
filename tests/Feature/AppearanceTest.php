<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppearanceTest extends TestCase
{
    public function test_pages_default_to_dark_mode_and_the_garage_style()
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-style="garage"', false)
            ->assertSee('class="dark"', false);
    }

    public function test_chosen_style_and_mode_come_from_cookies()
    {
        $this->withUnencryptedCookies(['style' => 'neon', 'appearance' => 'light'])
            ->get('/')
            ->assertSee('data-style="neon"', false)
            ->assertDontSee('class="dark"', false);
    }

    public function test_unknown_style_falls_back_to_default()
    {
        $this->withUnencryptedCookies(['style' => '"><script>'])
            ->get('/')
            ->assertSee('data-style="garage"', false)
            ->assertDontSee('"><script>', false);
    }
}
