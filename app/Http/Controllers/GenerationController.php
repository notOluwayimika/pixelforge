<?php

namespace App\Http\Controllers;

use App\Billing\CostEstimator;
use App\Enums\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->limit(50)->get();

        return Inertia::render('generations/Index', [
            'generations' => $generations->map->toHistoryArray(),
            // The page polls only while something can still change.
            'hasInFlight' => $generations->contains(fn (Generation $g) => ! $g->status->isFinal()),
        ]);
    }

    public function create(): Response
    {
        $pricing = $this->pixelforge->pricing();

        return Inertia::render('generations/Create', [
            'sizes' => $pricing->sizes(),
            'qualities' => $pricing->qualities(),
        ]);
    }

    /** Live estimate for the form. Computed server-side so it uses the exact same maths as billing. */
    public function estimate(GenerationRequest $request): JsonResponse
    {
        $input = $request->generationInput();

        return response()->json($this->estimator()->estimate(
            $input['prompt'], $input['size'], $input['quality'], count($input['reference_images']),
        )->toArray());
    }

    public function store(GenerationRequest $request): RedirectResponse
    {
        $this->createAndSubmit($request->user()->id, $request->generationInput());

        return to_route('generations.index');
    }

    /** A failed generation is retried as a NEW generation: new row, new idempotency key. */
    public function retry(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        abort_unless($generation->status === GenerationStatus::Failed, 422, 'Only failed generations can be retried.');

        $this->createAndSubmit($generation->user_id, $generation->only(['prompt', 'size', 'quality', 'reference_images']));

        return to_route('generations.index');
    }

    /**
     * The row is written BEFORE we talk to Pixelforge. Its idempotency key then makes every
     * retry of the POST safe, and its id (sent as metadata) lets an early webhook find it.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>}  $input
     */
    private function createAndSubmit(int $userId, array $input): Generation
    {
        $estimate = $this->estimator()->estimate(
            $input['prompt'], $input['size'], $input['quality'], count($input['reference_images']),
        );

        $generation = Generation::create([
            ...$input,
            'user_id' => $userId,
            'status' => GenerationStatus::Submitting,
            'idempotency_key' => (string) Str::uuid(),
            'estimated_typical_charge' => $estimate->typicalCharge(),
            'estimated_max_charge' => $estimate->maxCharge(),
        ]);

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }

    private function estimator(): CostEstimator
    {
        return new CostEstimator($this->pixelforge->pricing());
    }
}
