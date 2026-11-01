<?php

namespace App\Http\Requests;

use App\Services\GoogleAdsKeywordService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class KeywordSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'seeds' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:500'],
            'location' => ['nullable', 'array', 'max:'.GoogleAdsKeywordService::MAX_GEO_TARGETS],
            'location.*' => ['string', 'regex:/^\d+(:.*)?$/', 'max:200'],
            'language' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:'.GoogleAdsKeywordService::MAX_IDEAS],
        ];
    }

    /**
     * Seed keywords from the comma or newline separated input.
     *
     * @return array<int, string>
     */
    public function seeds(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $this->validated('seeds', '')))));
    }

    /**
     * Selected locations, encoded as "id:label" so the form can show names without an extra API call.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function locations(): array
    {
        $locations = array_map(function (string $location): array {
            [$id, $name] = array_pad(explode(':', $location, 2), 2, null);

            return ['id' => $id, 'name' => $name ?: $id];
        }, $this->validated('location') ?? []);

        return $locations ?: [['id' => (string) config('services.google_ads.default_geo'), 'name' => 'Italia']];
    }

    public function hasSearch(): bool
    {
        return $this->seeds() !== [] || filled($this->validated('url'));
    }
}
