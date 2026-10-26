<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiProviderDefaultsTest extends TestCase
{
    public function test_text_and_embedding_operations_default_to_gemini(): void
    {
        $this->assertSame('gemini', config('ai.default'));
        $this->assertSame('gemini', config('ai.default_for_embeddings'));
    }
}
