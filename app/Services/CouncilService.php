<?php

namespace App\Services;

use App\Jobs\RunCouncilDeliberation;
use App\Models\Advisor;
use App\Models\CouncilRoom;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouncilService
{
    private const CHILD_TIMEOUT = 900;

    public function __construct(private LlmCascade $cascade) {}

    public function ask(string $question, ?CouncilRoom $room = null): CouncilRoom
    {
        $advisors = Advisor::where('enabled', true)->orderBy('id')->get();

        if ($advisors->isEmpty()) {
            throw ValidationException::withMessages(['question' => 'No enabled advisors in the council. Enable at least one personality.']);
        }

        $settings = Setting::read('council');
        $peerReview = (bool) $settings['enable_peer_review'];
        $chairman = $settings['chairman_model'];

        $room ??= new CouncilRoom(['title' => Str::limit(Str::before(trim($question), "\n"), 58, '…')]);
        $room->fill([
            'status' => 'stage1_deliberating',
            'status_detail' => "Stage 1: Convening {$advisors->count()} independent advisors in parallel…",
            'progress' => $this->initialProgress($advisors, $peerReview, $chairman),
        ])->save();

        $room->messages()->create(['type' => 'user_question', 'sender' => 'OPERATOR', 'content' => trim($question)]);

        RunCouncilDeliberation::dispatch($room, trim($question), $advisors->toArray(), $peerReview)->onQueue('council');

        return $room;
    }

    public function deliberate(CouncilRoom $room, string $question, array $advisors, bool $peerReview): void
    {
        $previousVerdict = $room->messages()->where('type', 'chairman_verdict')->latest('id')->value('content');
        $context = $previousVerdict ? "\n\nPrior Council Verdict in this session (for context):\n".Str::substr($previousVerdict, 0, 1200)."\n" : '';
        $roomId = $room->id;

        $takes = $this->inParallel($advisors, 'advisorTake', [$roomId, $question, $context]);

        $reviews = [];

        if ($peerReview && count($takes) >= 2) {
            $room->patch(
                ['status' => 'stage2_peer_review', 'status_detail' => 'Stage 2: Advisors conducting anonymous peer review (Responses A–E)…'],
                [
                    'stage' => 2,
                    'stage_name' => 'Stage 2: Anonymous Peer Review (Responses A–E)',
                    'advisors' => collect($advisors)->mapWithKeys(fn (array $advisor) => [
                        $advisor['id'] => ['peer_status' => 'thinking', 'peer_action' => 'Reading & critiquing anonymized takes A–E…'],
                    ])->all(),
                ],
            );

            [$anonymized, $legend] = $this->anonymize($takes);

            $reviews = $this->inParallel($advisors, 'peerReview', [$roomId, $question, $anonymized, $legend]);
        }

        $this->chairmanVerdict($room, $question, $takes, $reviews);
    }

    public function advisorTake(array $advisor, string $roomId, string $question, string $context): array
    {
        $prompt = "You are {$advisor['name']} on an LLM Council.\n\n"
            ."Your thinking style: {$advisor['prompt']}\n\n"
            ."A user has brought this question/decision to the council:\n"
            ."---\n{$question}{$context}\n---\n\n"
            .'Respond strictly from your assigned perspective. Be direct, sharp, and specific. '
            .'Do NOT hedge or try to be balanced — lean 100% into your thinking lens. '
            ."The other advisors will cover the angles you are not covering.\n\n"
            .'Keep your response between 140 and 260 words. No preamble. Go straight into your analysis.';

        [$message, $elapsed] = $this->record($roomId, $advisor, $prompt, 'advisor_take', $advisor['name']);

        $room = CouncilRoom::findOrFail($roomId);
        $done = $this->countSince($room, 'advisor_take');
        $room->patch(
            ['status_detail' => "Stage 1: {$done}/{$room->progress['total_advisors']} advisors complete"],
            [
                'completed_advisors' => $done,
                'advisors' => [$advisor['id'] => ['status' => 'done', 'action' => "Take completed in {$elapsed}s", 'stage1_elapsed' => $elapsed]],
            ],
        );

        return $message->toArray();
    }

    public function peerReview(array $advisor, string $roomId, string $question, string $anonymized, string $legend): array
    {
        $prompt = "You are {$advisor['name']} ({$advisor['prompt']}), reviewing the anonymized outputs of an LLM Council.\n"
            ."The advisors independently answered this question:\n"
            ."---\n{$question}\n---\n\n"
            ."Here are their anonymized responses:\n\n{$anonymized}\n\n"
            ."Answer these three questions directly and concisely (reference responses by letter A, B, C, etc.):\n"
            ."1. **Strongest Response**: Which response is the strongest and why?\n"
            ."2. **Biggest Blind Spot**: Which response has the biggest blind spot and what is it missing?\n"
            ."3. **What Everyone Missed**: What did ALL responses miss that the council must consider?\n\n"
            .'Keep your peer review under 160 words. Be incisive.';

        [$message, $elapsed] = $this->record($roomId, $advisor, $prompt, 'peer_review', "{$advisor['name']} (Peer Review)", $legend);

        $room = CouncilRoom::findOrFail($roomId);
        $done = $this->countSince($room, 'peer_review');
        $room->patch(
            ['status_detail' => "Stage 2: {$done}/{$room->progress['total_reviewers']} peer reviews complete"],
            [
                'completed_reviewers' => $done,
                'advisors' => [$advisor['id'] => ['peer_status' => 'done', 'peer_action' => "Critique complete in {$elapsed}s", 'peer_elapsed' => $elapsed]],
            ],
        );

        return $message->toArray();
    }

    private function chairmanVerdict(CouncilRoom $room, string $question, array $takes, array $reviews): void
    {
        $settings = Setting::read('council');
        $model = $settings['chairman_model'];

        $room->patch(
            ['status' => 'stage3_synthesizing', 'status_detail' => "Stage 3: Chairman ({$model}) synthesizing final verdict…"],
            [
                'stage' => 3,
                'stage_name' => 'Stage 3: Chairman Synthesis & Final Verdict',
                'chairman' => ['status' => 'synthesizing', 'action' => "Synthesizing consensus, clashes, and recommendation ({$model})…"],
            ],
        );

        $takesText = collect($takes)->map(fn (array $take) => "**{$take['sender']} ({$take['model']}):**\n{$take['content']}")->implode("\n\n");
        $reviewsText = $reviews
            ? collect($reviews)->map(fn (array $review) => "**Review by {$review['sender']} [Key: {$review['anon_legend']}]:**\n{$review['content']}")->implode("\n\n")
            : 'Peer review round skipped.';

        $prompt = 'You are the Chairman of an LLM Council. Your job is to synthesize the independent analyses '
            .'of '.count($takes)." advisors and their peer reviews into a decisive, high-clarity final verdict.\n\n"
            ."The question brought to the council:\n"
            ."---\n{$question}\n---\n\n"
            ."ADVISOR RESPONSES:\n{$takesText}\n\n"
            ."PEER REVIEWS:\n{$reviewsText}\n\n"
            ."Produce the council verdict using this exact structure:\n\n"
            ."## 1. Where the Council Agrees\n"
            ."[Points multiple advisors converged on independently. High-confidence signals.]\n\n"
            ."## 2. Where the Council Clashes\n"
            ."[Genuine disagreements. Present both sides clearly and explain why reasonable perspectives clash here.]\n\n"
            ."## 3. Blind Spots the Council Caught\n"
            ."[Crucial insights that emerged from contrasting perspectives or peer review.]\n\n"
            ."## 4. The Chairman's Recommendation\n"
            ."[A clear, direct, actionable recommendation. Do NOT say 'it depends'. Take a definitive stance backed by reasoning. You may side with a single dissenter if their logic is strongest.]\n\n"
            ."## 5. The One Thing to Do First\n"
            ."[A single concrete, immediate next step the user should take Monday morning.]\n\n"
            .'Be direct, authoritative, and concrete.';

        $started = microtime(true);
        [$verdict, $usedModel] = $this->cascade->ask($prompt, $model, (bool) $settings['chairman_use_openrouter'], 120);
        $elapsed = round(microtime(true) - $started, 1);

        $room->messages()->create([
            'type' => 'chairman_verdict',
            'sender' => 'THE CHAIRMAN // FINAL VERDICT',
            'model' => $usedModel,
            'content' => $verdict,
            'elapsed_sec' => $elapsed,
        ]);

        $room->patch(
            ['status' => 'completed', 'status_detail' => 'Verdict delivered — Ready for follow-up debate'],
            [
                'stage' => 4,
                'stage_name' => 'Deliberation Completed',
                'chairman' => ['status' => 'done', 'elapsed' => $elapsed, 'action' => "Final verdict delivered in {$elapsed}s"],
            ],
        );
    }

    private function record(string $roomId, array $advisor, string $prompt, string $type, string $sender, ?string $legend = null): array
    {
        $started = microtime(true);
        [$answer, $model] = $this->cascade->ask($prompt, $advisor['model'], (bool) $advisor['use_openrouter']);
        $elapsed = round(microtime(true) - $started, 1);

        $message = CouncilRoom::findOrFail($roomId)->messages()->create([
            'type' => $type,
            'sender' => $sender,
            'icon' => $advisor['icon'],
            'color' => $advisor['color'],
            'model' => $model,
            'anon_legend' => $legend,
            'content' => $answer,
            'elapsed_sec' => $elapsed,
        ]);

        return [$message, $elapsed];
    }

    private function inParallel(array $advisors, string $method, array $arguments): array
    {
        $tasks = [];

        foreach ($advisors as $advisor) {
            $tasks[$advisor['id']] = static fn () => app(self::class)->{$method}($advisor, ...$arguments);
        }

        $results = Concurrency::run($tasks, self::CHILD_TIMEOUT);

        return collect($advisors)->map(fn (array $advisor) => $results[$advisor['id']])->all();
    }

    private function anonymize(array $takes): array
    {
        $shuffled = collect($takes)->shuffle()->values();
        $letters = $shuffled->keys()->map(fn (int $index) => 'Response '.chr(65 + $index));

        return [
            $shuffled->map(fn (array $take, int $index) => "**{$letters[$index]}:**\n{$take['content']}")->implode("\n\n"),
            $shuffled->map(fn (array $take, int $index) => "{$letters[$index]} = {$take['sender']}")->implode(', '),
        ];
    }

    private function countSince(CouncilRoom $room, string $type): int
    {
        $questionId = $room->messages()->where('type', 'user_question')->max('id');

        return $room->messages()->where('type', $type)->where('id', '>', $questionId)->count();
    }

    private function initialProgress(Collection $advisors, bool $peerReview, string $chairman): array
    {
        return [
            'stage' => 1,
            'stage_name' => 'Stage 1: Independent Deliberations',
            'total_advisors' => $advisors->count(),
            'completed_advisors' => 0,
            'total_reviewers' => $peerReview ? $advisors->count() : 0,
            'completed_reviewers' => 0,
            'advisors' => $advisors->mapWithKeys(fn (Advisor $advisor) => [$advisor->id => [
                'id' => $advisor->id,
                'name' => $advisor->name,
                'icon' => $advisor->icon,
                'color' => $advisor->color,
                'model' => $advisor->model,
                'status' => 'thinking',
                'action' => "Analyzing question from {$advisor->name} perspective…",
                'stage1_elapsed' => null,
                'peer_status' => $peerReview ? 'waiting' : 'skipped',
                'peer_action' => $peerReview ? 'Waiting for Stage 1 completion…' : 'Skipped',
                'peer_elapsed' => null,
            ]])->all(),
            'chairman' => [
                'model' => $chairman,
                'status' => 'waiting',
                'action' => 'Waiting for advisor takes and peer reviews…',
                'elapsed' => null,
            ],
        ];
    }
}
