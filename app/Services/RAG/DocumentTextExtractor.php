<?php

namespace App\Services\RAG;

use App\Models\Document;

interface DocumentTextExtractor
{
    /**
     * Extract the document's text, normalized to UTF-8 with "\n" line endings.
     *
     * @throws IngestionException if the file cannot be read, is of an unsupported type, or contains no text.
     */
    public function extract(Document $document): string;
}
