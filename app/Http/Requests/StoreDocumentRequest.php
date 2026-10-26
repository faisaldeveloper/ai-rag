<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Rules\TextFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreDocumentRequest extends FormRequest
{
    /**
     * The sha256 checksum of the uploaded file, computed once during validation.
     */
    private ?string $checksum = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('rag.max_upload_kilobytes'),
                'extensions:txt,md',
                new TextFile,
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('file')) {
                    return;
                }

                if (Document::query()->where('checksum', $this->checksum())->exists()) {
                    $validator->errors()->add('file', Document::DuplicateMessage);
                }
            },
        ];
    }

    /**
     * Get the sha256 checksum of the uploaded file's contents.
     */
    public function checksum(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $this->checksum ??= hash_file('sha256', $file->getRealPath());
    }
}
