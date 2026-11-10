/** Shapes of the JSON API resources the pages read (see app/Http/Resources). */

export type DocumentStatus = 'pending' | 'processing' | 'completed' | 'failed';

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
}

export interface SharedProps {
    auth: { user: User | null };
    [key: string]: unknown;
}

export interface KnowledgeDocument {
    id: number;
    title: string;
    original_filename: string;
    mime_type: string;
    checksum: string;
    status: DocumentStatus;
    error: string | null;
    created_at: string;
    updated_at: string;
}

export interface Citation {
    label: string;
    document_id: number;
    document_title: string;
    heading: string | null;
    page: number | null;
    chunk_id: number;
    score: number;
}

export interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    fallback: boolean;
    citations: Citation[];
    created_at: string;
}

export interface Conversation {
    id: number;
    title: string;
    messages?: Message[];
    created_at: string;
    updated_at: string;
}

export interface Source {
    label: string;
    document_id: number;
    document_title: string;
    chunk_id: number;
    chunk_index: number;
    heading: string | null;
    page: number | null;
    content: string;
}

export interface ChatReply {
    conversation_id: number;
    fallback: boolean;
    user_message: Message;
    assistant_message: Message;
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}
