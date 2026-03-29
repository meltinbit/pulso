<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFunnelRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_open' => ['boolean'],
            'steps' => ['required', 'array', 'min:2'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.event_name' => ['required', 'string', 'max:255'],
        ];
    }
}
