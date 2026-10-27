<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\RAG\DocumentTextExtractor;
use App\Services\RAG\IngestionException;
use App\Services\RAG\PlainTextDocumentExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlainTextDocumentExtractorTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_it_is_the_bound_extractor(): void
    {
        $this->assertInstanceOf(PlainTextDocumentExtractor::class, app(DocumentTextExtractor::class));
    }

    public function test_it_normalizes_line_endings_and_strips_the_bom_and_null_bytes(): void
    {
        $document = $this->storedDocument("\xEF\xBB\xBFLine one\r\nLine two\rLine\0 three\n");

        $this->assertSame("Line one\nLine two\nLine three\n", app(DocumentTextExtractor::class)->extract($document));
    }

    public function test_it_returns_markdown_unchanged(): void
    {
        $markdown = "# Setup\n\n```c\n#include <stdio.h>\n```\n\nCafé — 日本語 👍🏽\n";

        $this->assertSame($markdown, app(DocumentTextExtractor::class)->extract($this->storedDocument($markdown, 'text/markdown')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankContents(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => [" \r\n\t\n  "],
            'null bytes and whitespace only' => ["\0\0 \n\0"],
            'byte order mark only' => ["\xEF\xBB\xBF\n"],
        ];
    }

    #[DataProvider('blankContents')]
    public function test_it_rejects_content_without_text(string $contents): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('The document contains no text.');

        app(DocumentTextExtractor::class)->extract($this->storedDocument($contents));
    }

    public function test_it_rejects_content_that_is_not_utf8(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('The document is not valid UTF-8 text.');

        app(DocumentTextExtractor::class)->extract($this->storedDocument("Caf\xE9 latin-1 text"));
    }

    public function test_it_rejects_documents_that_are_not_plain_text(): void
    {
        $this->expectException(IngestionException::class);
        $this->expectExceptionMessage('Only plain text and Markdown documents are supported.');

        app(DocumentTextExtractor::class)->extract($this->storedDocument('%PDF-1.7', 'application/pdf'));
    }

    public function test_a_missing_file_fails_without_revealing_the_storage_path(): void
    {
        $document = Document::factory()->create(['storage_path' => 'missing-file.txt', 'mime_type' => 'text/plain']);

        try {
            app(DocumentTextExtractor::class)->extract($document);
            $this->fail('Expected an IngestionException.');
        } catch (IngestionException $exception) {
            $this->assertSame('The stored file could not be read.', $exception->getMessage());
            $this->assertStringNotContainsString('missing-file', $exception->getMessage());
        }
    }

    /**
     * Store the contents on the documents disk and create a document pointing at them.
     */
    private function storedDocument(string $contents, string $mimeType = 'text/plain'): Document
    {
        Storage::disk('documents')->put('stored.txt', $contents);

        return Document::factory()->create(['storage_path' => 'stored.txt', 'mime_type' => $mimeType]);
    }
}
