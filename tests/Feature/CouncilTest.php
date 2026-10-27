<?php

namespace Tests\Feature;

use App\Models\Advisor;
use App\Models\CouncilRoom;
use App\Models\Setting;
use App\Services\LlmCascade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CouncilTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['concurrency.default' => 'sync']);
        Setting::merge('openrouter', ['api_key' => 'sk-or-v1-abcdefghijklmnop']);
        Http::fake(['openrouter.ai/*' => Http::response(['model' => 'openai/gpt-4.1', 'choices' => [['message' => ['content' => 'Take']]]])]);
    }

    public function test_council_runs_all_three_stages_and_delivers_a_verdict(): void
    {
        Advisor::query()->update(['use_openrouter' => true, 'model' => 'openai/gpt-4.1']);
        Setting::merge('council', ['chairman_use_openrouter' => true, 'chairman_model' => 'openai/gpt-4.1']);

        $room = $this->postJson('/api/council/rooms', ['question' => "Ship it?\nMore detail"])->assertCreated()->json();

        $show = $this->getJson("/api/council/rooms/{$room['id']}")->assertOk();
        $types = collect($show->json('messages'))->countBy('type')->all();

        $this->assertSame(['user_question' => 1, 'advisor_take' => 5, 'peer_review' => 5, 'chairman_verdict' => 1], $types);
        $show->assertJsonPath('title', 'Ship it?')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress.stage', 4)
            ->assertJsonPath('progress.completed_advisors', 5)
            ->assertJsonPath('progress.completed_reviewers', 5);
        $this->assertStringContainsString('Response A', collect($show->json('messages'))->firstWhere('type', 'peer_review')['anon_legend']);

        $this->postJson('/api/council/rooms', ['question' => 'Follow up', 'room_id' => $room['id']])->assertCreated();
        $this->assertSame(5, CouncilRoom::find($room['id'])->progress['completed_advisors']);
    }

    public function test_peer_review_is_skipped_when_disabled(): void
    {
        Advisor::query()->update(['use_openrouter' => true, 'model' => 'openai/gpt-4.1']);
        $this->patchJson('/api/council/settings', ['enable_peer_review' => false, 'chairman_use_openrouter' => true])
            ->assertJsonPath('chairman_model', 'anthropic/claude-3.7-sonnet');

        $room = $this->postJson('/api/council/rooms', ['question' => 'Q'])->json();

        $this->assertSame(0, CouncilRoom::find($room['id'])->messages()->where('type', 'peer_review')->count());
    }

    public function test_council_requires_an_enabled_advisor(): void
    {
        Advisor::query()->update(['enabled' => false]);

        $this->postJson('/api/council/rooms', ['question' => 'Q'])->assertUnprocessable();
    }

    public function test_cli_failure_cascades_through_the_fallback_chain(): void
    {
        Process::fake(fn () => Process::result(exitCode: 1));

        [$text, $label] = app(LlmCascade::class)->ask('Prompt', 'claude-opus-5', false);

        $this->assertSame('Take', $text);
        $this->assertSame('fallback #2 (openrouter) // openai/gpt-4.1', $label);
        Process::assertRan(fn ($process) => $process->command[3] === 'claude-opus-5-5-medium');
        Process::assertRan(fn ($process) => $process->command[3] === 'gemini-3.8-flash-medium');
    }
}
