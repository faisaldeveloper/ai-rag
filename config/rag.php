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

    'embedding_batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 32),

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Target chunk size and overlap between consecutive chunks, in estimated
    | tokens (characters / 4, as no tokenizer is available).
    |
    */

    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 500),

    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 50),

    /*
    |--------------------------------------------------------------------------
    | Uploaded Documents
    |--------------------------------------------------------------------------
    |
    | Uploads are stored on a private disk (never publicly served) under a
    | random filename. The size limit is in kilobytes.
    |
    */

    'documents_disk' => env('RAG_DOCUMENTS_DISK', 'documents'),

    'max_upload_kilobytes' => 5 * 1024,

];
