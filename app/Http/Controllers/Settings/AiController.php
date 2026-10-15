<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\DiecastScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AiController extends Controller
{
    private const PROVIDERS = ['gemini', 'anthropic'];

    public function __construct(private DiecastScanner $scanner) {}

    /**
     * Show the AI scan settings page.
     */
    public function edit(): Response
    {
        $providers = [];

        foreach (self::PROVIDERS as $provider) {
            $saved = AppSetting::get("{$provider}_api_key");
            $env = config("services.{$provider}.key");
            $key = $saved ?? $env;

            $providers[$provider] = [
                'model' => $this->scanner->model($provider),
                'key_hint' => $key ? '••••'.substr($key, -4) : null,
                'key_source' => $saved ? 'settings' : ($env ? 'env' : null),
            ];
        }

        return Inertia::render('settings/ai', [
            'provider' => $this->scanner->provider(),
            'providers' => $providers,
        ]);
    }

    /**
     * Save provider, models and (optionally) new API keys.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'gemini_model' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.\-]+$/'],
            'anthropic_model' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.\-]+$/'],
            'gemini_api_key' => ['nullable', 'string', 'max:500'],
            'anthropic_api_key' => ['nullable', 'string', 'max:500'],
            'remove_keys' => ['array'],
            'remove_keys.*' => [Rule::in(self::PROVIDERS)],
        ], [
            '*.regex' => 'Nama model hanya boleh huruf kecil, angka, titik, dan tanda minus.',
        ]);

        AppSetting::put('scan_provider', $data['provider']);

        foreach (self::PROVIDERS as $provider) {
            AppSetting::put("{$provider}_model", $data["{$provider}_model"]);

            if (in_array($provider, $data['remove_keys'] ?? [], true)) {
                AppSetting::put("{$provider}_api_key", null);
            } elseif (filled($data["{$provider}_api_key"] ?? null)) {
                // An empty field means "keep the current key".
                AppSetting::put("{$provider}_api_key", trim($data["{$provider}_api_key"]));
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengaturan AI disimpan.']);

        return to_route('ai.edit');
    }

    /**
     * Test a key (typed or saved) and return the models it can use.
     */
    public function models(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $key = filled($data['api_key'] ?? null) ? trim($data['api_key']) : $this->scanner->key($data['provider']);

        if (blank($key)) {
            return response()->json(['message' => 'Isi API key dulu.'], 422);
        }

        try {
            return response()->json(['models' => $this->scanner->availableModels($data['provider'], $key)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
