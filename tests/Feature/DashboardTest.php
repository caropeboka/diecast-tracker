<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_dex_lists_furthest_along()
    {
        $user = User::factory()->create();
        $user->catalogs()->create(['brand' => 'Mini GT', 'name' => 'Mini GT 2026', 'source' => 'custom']);
        $catalog = $user->catalogs()->create(['brand' => 'Hot Wheels', 'name' => 'Hot Wheels Mainline 2026', 'year' => 2026, 'source' => 'hotwheels_wiki']);
        $seen = $catalog->items()->create(['number' => '001', 'sort' => 1, 'name' => 'A']);
        $catalog->items()->create(['number' => '002', 'sort' => 2, 'name' => 'B']);
        $user->diecasts()->create(['name' => 'A', 'status' => 'spotted'])
            ->forceFill(['catalog_item_id' => $seen->id, 'catalog_match' => 'confirmed'])->save();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                // A dex with no entries yet isn't shown.
                ->has('dex', 1)
                ->where('dex.0.name', 'Hot Wheels Mainline 2026')
                ->where('dex.0.total', 2)
                ->where('dex.0.seen', 1)
                ->where('dex.0.caught', 0));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }
}
