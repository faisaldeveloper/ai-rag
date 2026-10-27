<?php

namespace App\Http\Requests;

use App\Services\RAG\Retriever;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Any authenticated user may search; the auth:sanctum middleware guarantees one.
 */
class SearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:3', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Retriever::MaxLimit],
        ];
    }
}
