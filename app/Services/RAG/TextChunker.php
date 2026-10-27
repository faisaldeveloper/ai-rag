<?php

namespace App\Services\RAG;

use InvalidArgumentException;

/**
 * Splits text into overlapping chunks of roughly a fixed number of tokens.
 *
 * Markdown headings (outside code fences) always start a new chunk, and the
 * heading trail is kept on each chunk. Within a section, paragraphs are packed
 * together up to the chunk size; a paragraph too large for one chunk is split
 * at sentence boundaries, then word boundaries. Only a run of text without any
 * whitespace that is longer than a chunk is split mid-run, at a grapheme
 * boundary. Overlap is taken from the end of the previous chunk in the same
 * section, in whole words.
 *
 * There is no tokenizer, so tokens are estimated as characters / 4.
 */
class TextChunker
{
    /**
     * The number of characters assumed to make up one token.
     */
    public const CharactersPerToken = 4;

    /**
     * Patterns that cut a too-large piece of text into smaller segments, from
     * the most to the least preferred boundary. Each segment keeps its
     * trailing whitespace, so concatenating the segments restores the text.
     */
    private const SplitPatterns = [
        '/\S.*?(?:[.!?…]+(?:\s+|\z)|[。！？]+\s*|\z)/su',
        '/\S+\s*/u',
    ];

    /**
     * Create a new chunker.
     *
     * @param  int  $chunkSize  Maximum chunk size in estimated tokens.
     * @param  int  $chunkOverlap  Maximum overlap between consecutive chunks in estimated tokens.
     *
     * @throws InvalidArgumentException if the sizes are out of range.
     */
    public function __construct(private int $chunkSize, private int $chunkOverlap)
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1 token.');
        }

        if ($chunkOverlap < 0 || $chunkOverlap >= $chunkSize) {
            throw new InvalidArgumentException('The chunk overlap must be at least 0 and smaller than the chunk size.');
        }
    }

    /**
     * Split the text into chunks indexed consecutively from 0.
     *
     * @return list<TextChunk>
     */
    public function chunk(string $text): array
    {
        $chunks = [];

        foreach ($this->sections($text) as $section) {
            foreach ($this->pack($section['paragraphs']) as $content) {
                $chunks[] = new TextChunk(count($chunks), $content, self::estimateTokens($content), $section['heading']);
            }
        }

        return $chunks;
    }

    /**
     * Estimate the number of tokens in the given text.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CharactersPerToken);
    }

    /**
     * Group the text's paragraphs into sections that each start at a Markdown heading.
     *
     * A heading directly followed by another heading has no body of its own,
     * so it is merged into the following section rather than becoming a chunk.
     *
     * @return list<array{heading: ?string, paragraphs: list<string>}>
     */
    private function sections(string $text): array
    {
        $sections = [];
        $current = ['heading' => null, 'paragraphs' => []];
        $currentHasBody = false;
        $headings = [];

        foreach ($this->blocks($text) as [$level, $title, $content]) {
            if ($level === null) {
                $current['paragraphs'][] = $content;
                $currentHasBody = true;

                continue;
            }

            if ($currentHasBody) {
                $sections[] = $current;
                $current = ['heading' => null, 'paragraphs' => []];
                $currentHasBody = false;
            }

            $headings = array_filter($headings, fn (int $parentLevel): bool => $parentLevel < $level, ARRAY_FILTER_USE_KEY);
            $headings[$level] = $title;

            $current['heading'] = implode(' > ', $headings);
            $current['paragraphs'][] = $content;
        }

        if ($current['paragraphs'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * Split the text into heading and paragraph blocks.
     *
     * Paragraphs are separated by blank lines. Lines inside fenced code blocks
     * are never treated as headings or paragraph breaks.
     *
     * @return list<array{?int, ?string, string}> Each block as [heading level, heading title, content]; level and title are null for paragraphs.
     */
    private function blocks(string $text): array
    {
        $blocks = [];
        $lines = [];
        $inFence = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match('/^ {0,3}(?:```|~~~)/', $line)) {
                $inFence = ! $inFence;
                $lines[] = $line;

                continue;
            }

            if (! $inFence && preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$/u', $line, $heading)) {
                array_push($blocks, ...$this->paragraphBlock($lines));
                $blocks[] = [strlen($heading[1]), $heading[2], trim($line)];
                $lines = [];

                continue;
            }

            if (! $inFence && trim($line) === '') {
                array_push($blocks, ...$this->paragraphBlock($lines));
                $lines = [];

                continue;
            }

            $lines[] = $line;
        }

        array_push($blocks, ...$this->paragraphBlock($lines));

        return $blocks;
    }

    /**
     * Build the paragraph block for the given lines, if they contain any text.
     *
     * @param  list<string>  $lines
     * @return list<array{null, null, string}>
     */
    private function paragraphBlock(array $lines): array
    {
        $paragraph = trim(implode("\n", $lines));

        return $paragraph === '' ? [] : [[null, null, $paragraph]];
    }

    /**
     * Pack a section's paragraphs into chunks no larger than the chunk size.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function pack(array $paragraphs): array
    {
        $chunks = [];
        $current = '';
        $maxCharacters = $this->chunkSize * self::CharactersPerToken;
        $pieceLimit = ($this->chunkSize - $this->chunkOverlap) * self::CharactersPerToken;

        foreach ($paragraphs as $paragraph) {
            foreach ($this->split($paragraph, $pieceLimit) as $pieceIndex => $piece) {
                $separator = $pieceIndex === 0 ? "\n\n" : '';

                if ($current === '') {
                    $current = $piece;
                } elseif (mb_strlen(rtrim($current.$separator.$piece)) <= $maxCharacters) {
                    $current .= $separator.$piece;
                } else {
                    $chunks[] = rtrim($current);
                    $current = $this->startChunk(end($chunks), $pieceIndex === 0 ? "\n\n" : ' ', $piece, $maxCharacters);
                }
            }
        }

        if (trim($current) !== '') {
            $chunks[] = rtrim($current);
        }

        return $chunks;
    }

    /**
     * Start a new chunk with the given piece, prefixed by the overlap from the previous chunk when it fits.
     */
    private function startChunk(string $previousChunk, string $separator, string $piece, int $maxCharacters): string
    {
        $overlap = $this->overlapTail($previousChunk);

        if ($overlap === '' || mb_strlen(rtrim($overlap.$separator.$piece)) > $maxCharacters) {
            return $piece;
        }

        return $overlap.$separator.$piece;
    }

    /**
     * Get the longest tail of whole words of the chunk that fits within the overlap size.
     */
    private function overlapTail(string $chunk): string
    {
        $overlapCharacters = $this->chunkOverlap * self::CharactersPerToken;

        if ($overlapCharacters === 0) {
            return '';
        }

        return preg_match('/(?<=\s)\S.{0,'.($overlapCharacters - 1).'}\z/su', $chunk, $match) ? $match[0] : '';
    }

    /**
     * Split text into pieces no longer than the limit, preferring the earliest boundary type in SplitPatterns.
     *
     * @return list<string>
     */
    private function split(string $text, int $limit, int $patternIndex = 0): array
    {
        if (mb_strlen(rtrim($text)) <= $limit) {
            return [$text];
        }

        if (! isset(self::SplitPatterns[$patternIndex])) {
            return $this->splitGraphemes($text, $limit);
        }

        if (! preg_match_all(self::SplitPatterns[$patternIndex], $text, $matches)) {
            return $this->split($text, $limit, $patternIndex + 1);
        }

        $pieces = [];

        foreach ($matches[0] as $segment) {
            array_push($pieces, ...$this->split($segment, $limit, $patternIndex + 1));
        }

        return $pieces;
    }

    /**
     * Split text into pieces of at most the limit in characters without breaking a grapheme cluster.
     *
     * @return list<string>
     */
    private function splitGraphemes(string $text, int $limit): array
    {
        preg_match_all('/\X/u', $text, $matches);

        $pieces = [];
        $current = '';

        foreach ($matches[0] as $grapheme) {
            if ($current !== '' && mb_strlen($current.$grapheme) > $limit) {
                $pieces[] = $current;
                $current = '';
            }

            $current .= $grapheme;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
