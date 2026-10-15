<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AiVision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_can_open_ai_settings()
    {
        $this->actingAs(User::factory()->create())->get(route('ai.edit'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('ai.edit'))->assertOk();
    }

    public function test_admin_can_save_provider_model_and_key()
    {
        $this->actingAs($this->admin())
            ->put(route('ai.update'), [
                'provider' => 'anthropic',
                'gemini_model' => 'gemini-3.8-flash',
                'anthropic_model' => 'claude-opus-5-5',
                'gemini_api_key' => 'AIza-secret-1234',
                'anthropic_api_key' => '',
            ])
            ->assertRedirect(route('ai.edit'));

        $scanner = app(AiVision::class);
        $this->assertSame('anthropic', $scanner->provider());
        $this->assertSame('AIza-secret-1234', $scanner->key('gemini'));

        // Stored encrypted, never in plain text.
        $this->assertStringNotContainsString('AIza-secret-1234', DB::table('app_settings')->where('key', 'gemini_api_key')->value('value'));
    }

    public function test_empty_key_field_keeps_existing_key_and_remove_clears_it()
    {
        AppSetting::put('gemini_api_key', 'AIza-keep-me');
        $payload = ['provider' => 'gemini', 'gemini_model' => 'gemini-3.8-flash', 'anthropic_model' => 'claude-opus-5-5'];

        $this->actingAs($admin = $this->admin())->put(route('ai.update'), [...$payload, 'gemini_api_key' => '']);
        $this->assertSame('AIza-keep-me', AppSetting::get('gemini_api_key'));

        $this->actingAs($admin)->put(route('ai.update'), [...$payload, 'remove_keys' => ['gemini']]);
        $this->assertNull(AppSetting::get('gemini_api_key'));
    }

    public function test_settings_page_never_exposes_full_key()
    {
        AppSetting::put('gemini_api_key', 'AIza-very-secret-9876');

        $this->actingAs($this->admin())
            ->get(route('ai.edit'))
            ->assertDontSee('AIza-very-secret')
            ->assertInertia(fn ($page) => $page->where('providers.gemini.key_hint', '••••9876'));
    }

    public function test_testing_a_gemini_key_lists_usable_models()
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/gemini-3.8-flash-tts', 'supportedGenerationMethods' => ['generateContent']],
        ]])]);

        $this->actingAs($this->admin())
            ->postJson(route('ai.models'), ['provider' => 'gemini', 'api_key' => 'AIza-test'])
            ->assertOk()
            ->assertExactJson(['models' => ['gemini-3.8-flash']]);
    }

    public function test_invalid_gemini_key_is_reported()
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 400]], 400)]);

        $this->actingAs($this->admin())
            ->postJson(route('ai.models'), ['provider' => 'gemini', 'api_key' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'API key Gemini tidak valid.');
    }
}
