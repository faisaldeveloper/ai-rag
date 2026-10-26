<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | The provider (a key in config/ai.php), model, and vector dimension used
    | to embed documents and questions. Changing the dimension requires the
    | vector columns to be recreated and every document to be re-embedded.
    |
    */

    'embedding_provider' => env('RAG_EMBEDDING_PROVIDER', 'gemini'),

    'embedding_model' => env('RAG_EMBEDDING_MODEL', 'gemini-embedding-001'),

    'embedding_dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),

];
