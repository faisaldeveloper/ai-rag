<?php

namespace App\Enums;

/**
 * What an embedding will be used for, so the provider can optimize the vector.
 *
 * The values are Gemini's task type names; other providers ignore the task type.
 */
enum EmbeddingTaskType: string
{
    case RetrievalDocument = 'RETRIEVAL_DOCUMENT';
    case RetrievalQuery = 'RETRIEVAL_QUERY';
}
