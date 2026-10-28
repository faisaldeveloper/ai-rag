<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Any authenticated user may ask; the auth:sanctum middleware guarantees one.
 *
 * A conversation of another user fails validation exactly like one that does
 * not exist, so its existence is not revealed.
 */
class ChatRequest extends FormRequest
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
            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('conversations', 'id')->where('user_id', $this->user()->getKey()),
            ],
            'message' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
