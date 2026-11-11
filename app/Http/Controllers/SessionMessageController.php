<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Services\AgentSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SessionMessageController extends Controller
{
    public function store(Request $request, AgentSession $agentSession, AgentSessionManager $manager): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required_without:images', 'nullable', 'string'],
            'images' => ['array', 'max:6'],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'],
        ]);

        $images = array_map(fn ($file) => $this->saveImage($agentSession, $file), $request->file('images', []));
        $text = $data['text'] ?? '';

        if ($text === '') {
            $text = count($images) === 1 ? 'Look at this image.' : 'Look at these images.';
        }

        if (! $manager->prompt($agentSession, $text, $images)) {
            return response()->json(['message' => 'Failed to send keystrokes to tmux'], 502);
        }

        return response()->json(['sent' => true], 201);
    }

    public function image(AgentSession $agentSession, string $file): BinaryFileResponse
    {
        $path = $this->imageDir($agentSession).'/'.$file;

        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroy(AgentSession $agentSession): Response
    {
        $agentSession->messages()->delete();
        File::deleteDirectory($this->imageDir($agentSession));

        return response()->noContent();
    }

    private function saveImage(AgentSession $agentSession, $file): string
    {
        $dir = $this->imageDir($agentSession);
        File::ensureDirectoryExists($dir);

        $name = Str::uuid().'.'.$file->extension();
        $file->move($dir, $name);

        return "{$dir}/{$name}";
    }

    private function imageDir(AgentSession $agentSession): string
    {
        return rtrim(config('agenthost.upload_dir'), '/').'/session-'.$agentSession->id;
    }
}
