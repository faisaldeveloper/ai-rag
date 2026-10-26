<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates an upload by the MIME type detected from its bytes, ignoring the
 * client-supplied filename and Content-Type header.
 *
 * Any text/* type is accepted rather than only text/plain because content
 * sniffing labels ordinary Markdown as text/x-c, text/html, etc. whenever it
 * contains code samples. Binaries and empty files are rejected.
 */
class TextFile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'text/')) {
            $fail('The :attribute must contain plain text or Markdown.');
        }
    }
}
