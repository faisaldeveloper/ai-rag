import { useCallback, useEffect, useRef, useState } from 'react';
import { api, friendlyError } from '../lib/api';
import type { KnowledgeDocument, Paginated } from '../types';

export const PollIntervalMs = 3000;

const isInProgress = (document: KnowledgeDocument): boolean => document.status === 'pending' || document.status === 'processing';

/**
 * Load one page of documents, and reload it every PollIntervalMs while any
 * document on it is still pending or processing. Polling pauses while the
 * tab is hidden and stops as soon as nothing is in progress.
 */
export function useDocuments(page: number) {
    const [documents, setDocuments] = useState<KnowledgeDocument[]>([]);
    const [lastPage, setLastPage] = useState(1);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const latestRequest = useRef(0);

    const refresh = useCallback(async () => {
        const request = ++latestRequest.current;

        try {
            const response = await api<Paginated<KnowledgeDocument>>(`/documents?page=${page}`);
            if (request === latestRequest.current) {
                setDocuments(response.data);
                setLastPage(response.meta.last_page);
                setError(null);
            }
        } catch (caught) {
            if (request === latestRequest.current) {
                setError(friendlyError(caught));
            }
        } finally {
            if (request === latestRequest.current) {
                setIsLoading(false);
            }
        }
    }, [page]);

    useEffect(() => {
        setIsLoading(true);
        void refresh();
    }, [refresh]);

    const isPolling = documents.some(isInProgress);

    useEffect(() => {
        if (!isPolling) {
            return;
        }

        const timer = window.setTimeout(() => {
            if (document.hidden) {
                return;
            }
            void refresh();
        }, PollIntervalMs);

        const resume = () => {
            if (!document.hidden) {
                void refresh();
            }
        };
        document.addEventListener('visibilitychange', resume);

        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', resume);
        };
    }, [isPolling, documents, refresh]);

    return { documents, lastPage, isLoading, isPolling, error, refresh };
}
