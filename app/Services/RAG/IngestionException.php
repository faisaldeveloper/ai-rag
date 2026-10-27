<?php

namespace App\Services\RAG;

use RuntimeException;

/**
 * A document cannot be ingested because of its content.
 *
 * The message is shown to administrators as the document's error, so it must
 * never contain file contents, storage paths, or other internal details.
 */
class IngestionException extends RuntimeException
{
    //
}
