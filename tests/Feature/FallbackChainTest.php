<?php

namespace Tests\Feature;

use App\Models\Fallback;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FallbackChainTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_adding_a_fallback_with_key_arms_openrouter_and_syncs_defaults(): void
    {
        $this->postJson('/api/fallbacks', ['provider' => 'openrouter', 'model' => 'openai/gpt-4.1', 'api_key' => 'sk-or-v1-abcdefghijklmnop'])
            ->assertCreated()
            ->assertJsonMissingPath('api_key');

        $state = $this->getJson('/api/state')->assertOk();

        $state->assertJsonPath('openrouter.has_key', true)
            ->assertJsonPath('openrouter.masked_key', 'sk-or-v1-••••••••mnop')
            ->assertJsonPath('fallbacks.3.model', 'openai/gpt-4.1')
            ->assertJsonPath('fallbacks.0.has_key', true);

        $this->assertSame('anthropic/claude-3.7-sonnet', Setting::read('defaults')['openrouter_model']);
        $this->assertSame(['anthropic/claude-3.7-sonnet', 'deepseek/deepseek-r1', 'openai/gpt-4.1'], Setting::read('openrouter')['fallback_models']);
        $this->assertNotSame('sk-or-v1-abcdefghijklmnop', Fallback::query()->toBase()->latest('id')->value('api_key'));
    }

    public function test_moving_and_removing_fallbacks_reorders_the_chain(): void
    {
        [$first, $second, $third] = Fallback::chain()->modelKeys();

        $this->patchJson("/api/fallbacks/{$third}", ['direction' => 'up'])->assertNoContent();
        $this->assertSame([$first, $third, $second], Fallback::chain()->modelKeys());

        $this->patchJson("/api/fallbacks/{$first}", ['direction' => 'up'])->assertNoContent();
        $this->assertSame([$first, $third, $second], Fallback::chain()->modelKeys());

        $this->deleteJson("/api/fallbacks/{$third}")->assertNoContent();
        $this->assertSame([$first, $second], Fallback::chain()->modelKeys());

        $this->patchJson("/api/fallbacks/{$first}", ['direction' => 'sideways'])->assertUnprocessable();
    }
}
