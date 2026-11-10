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
    | Whether to send a task type (RETRIEVAL_DOCUMENT for chunks, RETRIEVAL_QUERY
    | for questions) to providers that support it, currently only Gemini. Each
    | document records whether its vectors used task types; after changing
    | this, re-embed the documents with rag:reembed --all.
    */

    'embedding_task_types' => (bool) env('RAG_EMBEDDING_TASK_TYPES', true),

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
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | How many chunks a search returns by default, and the minimum cosine
    | similarity (0 to 1) a chunk needs to be returned. The minimum score
    | is uncalibrated for now, so it lets every non-opposed chunk through.
    | The rate limit is the number of searches per user per minute.
    |
    */

    'top_k' => (int) env('RAG_TOP_K', 5),

    'min_score' => (float) env('RAG_MIN_SCORE', 0.68),

    'search_rate_limit' => (int) env('RAG_SEARCH_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Answer Generation
    |--------------------------------------------------------------------------
    |
    | The provider (a key in config/ai.php) and model that answer questions
    | from the retrieved chunks, the request timeout in seconds, and the
    | sampling temperature. Leave RAG_LLM_TEMPERATURE empty for models
    | that reject a temperature. The chat rate limit is the number of
    | questions per user per minute.
    |
    */

    'llm_provider' => env('RAG_LLM_PROVIDER', 'gemini'),

    'llm_model' => env('RAG_LLM_MODEL', 'gemini-3.5-flash-lite'),

    'llm_timeout' => (int) env('RAG_LLM_TIMEOUT', 30),

    'llm_temperature' => is_numeric(env('RAG_LLM_TEMPERATURE', 0.1)) ? (float) env('RAG_LLM_TEMPERATURE', 0.1) : null,

    'chat_rate_limit' => (int) env('RAG_CHAT_RATE_LIMIT', 10),

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
