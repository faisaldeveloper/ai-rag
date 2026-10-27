<?php

namespace App\Services;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentUploadService
{
    /**
     * Store an uploaded file on the private documents disk, record it as a pending document, and queue its processing.
     *
     * The file is stored under a random name; the user-supplied name is only
     * kept as metadata. If the database insert fails the stored file is removed.
     * The processing job is only queued once the document row is committed.
     *
     * @throws ValidationException if any user already uploaded a file with this checksum.
     */
    public function upload(User $user, UploadedFile $file, string $checksum, ?string $title = null): Document
    {
        $originalFilename = $this->cleanFilename($file->getClientOriginalName());
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $this->disk()->putFileAs('', $file, Str::uuid().'.'.$extension);

        try {
            return DB::transaction(function () use ($user, $file, $checksum, $title, $originalFilename, $path): Document {
                $document = $user->documents()->create([
                    'title' => $title ?: pathinfo($originalFilename, PATHINFO_FILENAME),
                    'original_filename' => $originalFilename,
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'checksum' => $checksum,
                ]);

                ProcessDocument::dispatch($document)->afterCommit();

                return $document;
            });
        } catch (Throwable $exception) {
            $this->disk()->delete($path);

            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['file' => Document::DuplicateMessage]);
            }

            throw $exception;
        }
    }

    /**
     * Delete a document and its stored file. Chunks are removed by the database cascade.
     *
     * The row is deleted first so a failure never leaves a document pointing at a missing file.
     */
    public function delete(Document $document): void
    {
        $document->delete();

        $this->disk()->delete($document->storage_path);
    }

    /**
     * Reduce a client-supplied filename to a safe, bounded display name.
     */
    private function cleanFilename(string $filename): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $filename))) ?? '';

        return Str::limit($name, 255, '') ?: 'document';
    }

    /**
     * Get the private disk that uploaded documents are stored on.
     */
    private function disk(): Filesystem
    {
        return Storage::disk(config('rag.documents_disk'));
    }
}
