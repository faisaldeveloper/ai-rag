<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The knowledge base is shared: admins manage every document, whoever uploaded
 * it, and regular users cannot manage documents at all. Regular users reach
 * document content only through retrieval, which uses Document::searchable().
 */
class DocumentPolicy
{
    /**
     * Determine whether the user can list all documents.
     */
    public function viewAny(User $user): Response
    {
        return $this->manage($user);
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): Response
    {
        return $this->manage($user);
    }

    /**
     * Determine whether the user can upload documents.
     */
    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): Response
    {
        return $this->manage($user);
    }

    /**
     * Determine whether the user can retry processing the document.
     */
    public function retry(User $user, Document $document): Response
    {
        return $this->manage($user);
    }

    /**
     * Allow only admins to manage documents.
     */
    private function manage(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::denyWithStatus(403, 'Only administrators can manage documents.');
    }
}
