<?php

namespace App\Services\RAG;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * Extracts text from plain text and Markdown documents on the private documents disk.
 */
class PlainTextDocumentExtractor implements DocumentTextExtractor
{
    /**
     * Extract the document's text, normalized to UTF-8 with "\n" line endings.
     *
     * The byte order mark and null bytes are removed.
     *
     * @throws IngestionException if the file cannot be read, is not UTF-8 text, or contains no text.
     */
    public function extract(Document $document): string
    {
        if (! str_starts_with($document->mime_type, 'text/')) {
            throw new IngestionException('Only plain text and Markdown documents are supported.');
        }

        try {
            $contents = Storage::disk(config('rag.documents_disk'))->get($document->storage_path);
        } catch (FilesystemException) {
            $contents = null;
        }

        if ($contents === null) {
            throw new IngestionException('The stored file could not be read.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new IngestionException('The document is not valid UTF-8 text.');
        }

        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $contents);

        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, strlen("\u{FEFF}"));
        }

        if (trim($text) === '') {
            throw new IngestionException('The document contains no text.');
        }

        return $text;
    }
}
