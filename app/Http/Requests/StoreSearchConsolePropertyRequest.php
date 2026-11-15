<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSearchConsolePropertyRequest extends FormRequest
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
            'site_url' => ['required', 'string', 'max:255'],
            'ga_connection_id' => [
                'required',
                Rule::exists('ga_connections', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
