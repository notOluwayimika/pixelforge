<?php

namespace App\Http\Requests;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own limits, so a bad request is caught here with a clear message instead
 * of after it has been queued. Sizes and qualities come from the live pricing, not a hardcoded list.
 */
class GenerationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = app(PixelforgeClient::class)->pricing();

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Blank reference rows from the form are not references.
        $this->merge([
            'reference_images' => array_values(array_filter(
                array_map(fn ($url) => is_string($url) ? trim($url) : $url, (array) $this->input('reference_images', [])),
                fn ($url) => $url !== '' && $url !== null,
            )),
        ]);
    }

    /**
     * The validated input, typed.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    public function generationInput(): array
    {
        return [
            'prompt' => $this->string('prompt')->value(),
            'size' => $this->string('size')->value(),
            'quality' => $this->string('quality')->value(),
            'reference_images' => array_values(array_map(strval(...), (array) $this->validated('reference_images'))),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Pixelforge accepts at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }
}
