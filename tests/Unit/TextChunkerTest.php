<?php

namespace Tests\Unit;

use App\Services\RAG\TextChunk;
use App\Services\RAG\TextChunker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blankTexts(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\n \t \r\n"],
        ];
    }

    #[DataProvider('blankTexts')]
    public function test_blank_text_produces_no_chunks(string $text): void
    {
        $this->assertSame([], (new TextChunker(500, 50))->chunk($text));
    }

    public function test_short_text_becomes_a_single_chunk_with_an_estimated_token_count(): void
    {
        $chunks = (new TextChunker(500, 50))->chunk("  Refunds are issued within 30 days.\n");

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->index);
        $this->assertSame('Refunds are issued within 30 days.', $chunks[0]->content);
        $this->assertSame(9, $chunks[0]->tokenCount);
        $this->assertNull($chunks[0]->heading);
    }

    public function test_paragraphs_are_packed_together_until_the_chunk_size_is_reached(): void
    {
        $paragraphs = ['First paragraph here.', 'Second paragraph here.', 'Third paragraph here.', 'Fourth paragraph here.'];

        $chunks = (new TextChunker(12, 0))->chunk(implode("\n\n", $paragraphs));

        $this->assertSame([
            "First paragraph here.\n\nSecond paragraph here.",
            "Third paragraph here.\n\nFourth paragraph here.",
        ], $this->contents($chunks));
    }

    public function test_one_huge_paragraph_is_split_at_sentence_boundaries(): void
    {
        $sentences = array_map(fn (int $number): string => "Sentence number {$number} is right here.", range(1, 40));
        $text = implode(' ', $sentences);

        $chunks = (new TextChunker(20, 0))->chunk($text);

        $this->assertGreaterThan(10, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk->content));
            $this->assertStringEndsWith('.', $chunk->content);
            $this->assertStringStartsWith('Sentence number', $chunk->content);
        }

        $this->assertSame($text, implode(' ', $this->contents($chunks)));
    }

    public function test_a_sentence_longer_than_a_chunk_falls_back_to_word_boundaries(): void
    {
        $words = array_map(fn (int $number): string => str_repeat('a', $number % 9 + 1).$number, range(1, 120));
        $text = implode(' ', $words);

        $chunks = (new TextChunker(20, 0))->chunk($text);

        $this->assertGreaterThan(5, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk->content));

            foreach (explode(' ', $chunk->content) as $word) {
                $this->assertContains($word, $words, 'A word was split across chunks.');
            }
        }

        $this->assertSame($text, implode(' ', $this->contents($chunks)));
    }

    public function test_a_single_run_without_whitespace_longer_than_a_chunk_is_split_as_a_last_resort(): void
    {
        $text = str_repeat('x', 200);

        $chunks = (new TextChunker(20, 0))->chunk($text);

        $this->assertSame([80, 80, 40], array_map(fn (TextChunk $chunk): int => mb_strlen($chunk->content), $chunks));
        $this->assertSame($text, implode('', $this->contents($chunks)));
    }

    public function test_markdown_headings_start_new_chunks_and_are_recorded_as_a_trail(): void
    {
        $markdown = <<<'MD'
            Intro paragraph.

            # Guide

            ## Install

            Run the installer.

            ```bash
            # not a heading

            echo hi
            ```

            ## Usage

            Open the app.

            ### Shortcuts ###

            Press Ctrl+K.
            MD;

        $chunks = (new TextChunker(500, 50))->chunk($markdown);

        $this->assertSame([
            'Intro paragraph.',
            "# Guide\n\n## Install\n\nRun the installer.\n\n```bash\n# not a heading\n\necho hi\n```",
            "## Usage\n\nOpen the app.",
            "### Shortcuts ###\n\nPress Ctrl+K.",
        ], $this->contents($chunks));
        $this->assertSame(
            [null, 'Guide > Install', 'Guide > Usage', 'Guide > Usage > Shortcuts'],
            array_map(fn (TextChunk $chunk): ?string => $chunk->heading, $chunks),
        );
    }

    public function test_overlap_does_not_cross_headings(): void
    {
        $chunks = (new TextChunker(20, 5))->chunk("# One\n\nalpha beta gamma\n\n# Two\n\ndelta epsilon");

        $this->assertSame(["# One\n\nalpha beta gamma", "# Two\n\ndelta epsilon"], $this->contents($chunks));
    }

    public function test_consecutive_chunks_overlap_by_whole_words_within_the_configured_size(): void
    {
        $words = array_map(fn (int $number): string => sprintf('w%03d', $number), range(1, 100));
        $text = implode(' ', $words);

        $chunks = (new TextChunker(20, 5))->chunk($text);

        $this->assertGreaterThan(3, count($chunks));
        $previousWords = null;
        $covered = [];

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk->content));
            $chunkWords = explode(' ', $chunk->content);

            if ($previousWords !== null) {
                $overlap = array_values(array_intersect($chunkWords, $previousWords));

                $this->assertNotEmpty($overlap);
                $this->assertSame(array_slice($previousWords, -count($overlap)), $overlap, 'The overlap is not the tail of the previous chunk.');
                $this->assertSame(array_slice($chunkWords, 0, count($overlap)), $overlap, 'The overlap is not the head of the next chunk.');
                $this->assertLessThanOrEqual(20, mb_strlen(implode(' ', $overlap)));
                $this->assertGreaterThan(count($overlap), count($chunkWords), 'A chunk contains nothing but overlap.');
            }

            $covered = [...$covered, ...$chunkWords];
            $previousWords = $chunkWords;
        }

        $this->assertSame($words, array_values(array_unique($covered)));
    }

    public function test_unicode_text_is_measured_in_characters_and_never_broken(): void
    {
        $paragraph = 'Ünïcödé café — naïve résumé. 日本語のテキスト。これは文です。 Emoji 👍🏽 and 🎉 party! Ελληνικά κείμενα εδώ.';
        $text = implode("\n\n", array_fill(0, 6, $paragraph));

        foreach ([0, 2] as $overlap) {
            $chunks = (new TextChunker(10, $overlap))->chunk($text);

            foreach ($chunks as $chunk) {
                $this->assertTrue(mb_check_encoding($chunk->content, 'UTF-8'));
                $this->assertLessThanOrEqual(40, mb_strlen($chunk->content));
                $this->assertSame((int) ceil(mb_strlen($chunk->content) / 4), $chunk->tokenCount);
                $this->assertSame(0, preg_match('/^\p{M}/u', $chunk->content), 'A chunk starts with a detached combining mark.');
            }
        }

        $withoutOverlap = (new TextChunker(10, 0))->chunk($text);
        $this->assertSame(
            preg_replace('/\s+/u', '', $text),
            preg_replace('/\s+/u', '', implode('', $this->contents($withoutOverlap))),
        );
    }

    public function test_chunk_indexes_are_consecutive_from_zero(): void
    {
        $text = "# A\n\n".str_repeat('Lorem ipsum dolor sit amet. ', 40)."\n\n# B\n\n".str_repeat('Consectetur adipiscing elit. ', 40);

        $chunks = (new TextChunker(30, 5))->chunk($text);

        $this->assertGreaterThan(4, count($chunks));
        $this->assertSame(range(0, count($chunks) - 1), array_map(fn (TextChunk $chunk): int => $chunk->index, $chunks));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidSizes(): array
    {
        return [
            'zero chunk size' => [0, 0],
            'negative overlap' => [10, -1],
            'overlap equal to the chunk size' => [10, 10],
        ];
    }

    #[DataProvider('invalidSizes')]
    public function test_it_rejects_invalid_sizes(int $chunkSize, int $chunkOverlap): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker($chunkSize, $chunkOverlap);
    }

    /**
     * @param  list<TextChunk>  $chunks
     * @return list<string>
     */
    private function contents(array $chunks): array
    {
        return array_map(fn (TextChunk $chunk): string => $chunk->content, $chunks);
    }
}
