<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PromptImageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadDir = sys_get_temp_dir().'/agent-host-uploads-'.uniqid();
        config(['agenthost.upload_dir' => $this->uploadDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadDir);

        parent::tearDown();
    }

    public function test_cli_agent_gets_the_saved_image_paths_in_its_prompt(): void
    {
        Process::fake();
        $session = AgentSession::create(['name' => 'Agent_img', 'title' => 'I', 'agent' => 'claude', 'model' => 'claude-sonnet-5-5-medium', 'workdir' => '/tmp', 'state' => 'idle']);

        $this->post("/api/sessions/{$session->id}/messages", [
            'text' => 'What is wrong here?',
            'images' => [UploadedFile::fake()->image('error.png', 40, 40)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $session->messages()->first()->attachments[0];
        $this->assertFileExists($path);
        $this->assertStringStartsWith($this->uploadDir.'/session-'.$session->id.'/', $path);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['tmux', 'send-keys', '-t', 'Agent_img', '-l', "What is wrong here? {$path}"]);

        $this->get("/api/sessions/{$session->id}/images/".basename($path))->assertOk();
    }

    public function test_openrouter_receives_images_as_content_parts(): void
    {
        Setting::merge('openrouter', ['api_key' => 'sk-or-v1-abcdefghijklmnop']);
        Http::fake(['openrouter.ai/*' => Http::response(['model' => 'openai/gpt-4.1', 'choices' => [['message' => ['content' => 'A cat']]]])]);
        $session = $this->postJson('/api/sessions', ['title' => 'Chat', 'agent' => 'openrouter', 'model' => 'openai/gpt-4.1', 'workdir' => '/tmp'])->json();

        $this->post("/api/sessions/{$session['id']}/messages", [
            'images' => [UploadedFile::fake()->image('cat.jpg', 20, 20)],
        ], ['Accept' => 'application/json'])->assertCreated();

        Http::assertSent(function ($request) {
            $content = $request['messages'][0]['content'];

            return count($request['messages']) === 1
                && $content[0] === ['type' => 'text', 'text' => 'Look at this image.']
                && str_starts_with($content[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }

    public function test_rejects_non_image_uploads(): void
    {
        $session = AgentSession::create(['name' => 'Agent_bad', 'title' => 'B', 'agent' => 'claude', 'model' => 'claude-sonnet-5-5-medium', 'workdir' => '/tmp', 'state' => 'idle']);

        $this->post("/api/sessions/{$session->id}/messages", [
            'text' => 'hi',
            'images' => [UploadedFile::fake()->create('script.php', 1, 'text/x-php')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
