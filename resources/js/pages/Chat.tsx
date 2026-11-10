import { useCallback, useEffect, useRef, useState } from 'react';
import Alert from '../components/Alert';
import ChatInput from '../components/ChatInput';
import ConversationList from '../components/ConversationList';
import MessageItem from '../components/MessageItem';
import SourcePanel from '../components/SourcePanel';
import { useCountdown } from '../hooks/useCountdown';
import AppLayout from '../layouts/AppLayout';
import { api, ApiError, friendlyError, postJson } from '../lib/api';
import type { ChatReply, Citation, Conversation, Message, Paginated, Source } from '../types';

interface OpenSource {
    conversationId: number;
    messageId: number;
    citation: Citation;
}

export default function Chat() {
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [conversationPage, setConversationPage] = useState(1);
    const [hasMoreConversations, setHasMoreConversations] = useState(false);
    const [isLoadingConversations, setIsLoadingConversations] = useState(true);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [isLoadingThread, setIsLoadingThread] = useState(false);
    const [draft, setDraft] = useState('');
    const [pendingQuestion, setPendingQuestion] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [retryIn, startRetryCountdown] = useCountdown();
    const [openSource, setOpenSource] = useState<OpenSource | null>(null);
    const [source, setSource] = useState<Source | null>(null);
    const [sourceError, setSourceError] = useState<string | null>(null);
    const sourceCache = useRef(new Map<string, Source>());
    const openSourceKey = useRef<string | null>(null);
    const sourceOpener = useRef<HTMLElement | null>(null);
    const threadEnd = useRef<HTMLDivElement>(null);

    const loadConversations = useCallback(async (page: number) => {
        setIsLoadingConversations(true);
        try {
            const response = await api<Paginated<Conversation>>(`/conversations?page=${page}`);
            setConversations((current) => (page === 1 ? response.data : [...current, ...response.data]));
            setConversationPage(page);
            setHasMoreConversations(response.meta.current_page < response.meta.last_page);
        } catch (caught) {
            setError(friendlyError(caught));
        } finally {
            setIsLoadingConversations(false);
        }
    }, []);

    useEffect(() => {
        void loadConversations(1);
    }, [loadConversations]);

    useEffect(() => {
        threadEnd.current?.scrollIntoView?.({ block: 'end' });
    }, [messages, pendingQuestion]);

    async function selectConversation(id: number) {
        setActiveId(id);
        setOpenSource(null);
        setError(null);
        setIsLoadingThread(true);
        try {
            const response = await api<{ data: Conversation }>(`/conversations/${id}`);
            setMessages(response.data.messages ?? []);
        } catch (caught) {
            setMessages([]);
            setError(friendlyError(caught));
        } finally {
            setIsLoadingThread(false);
        }
    }

    function startNewConversation() {
        setActiveId(null);
        setMessages([]);
        setOpenSource(null);
        setError(null);
    }

    async function send() {
        const question = draft.trim();
        setPendingQuestion(question);
        setDraft('');
        setError(null);

        try {
            const response = await postJson<{ data: ChatReply }>('/chat', { message: question, conversation_id: activeId });
            const reply = response.data;
            setMessages((current) => [...current, reply.user_message, reply.assistant_message]);

            if (activeId !== reply.conversation_id) {
                setActiveId(reply.conversation_id);
            }
            void loadConversations(1);
        } catch (caught) {
            setDraft(question);
            setError(friendlyError(caught));
            if (caught instanceof ApiError && caught.retryAfter !== null) {
                startRetryCountdown(caught.retryAfter);
            }
        } finally {
            setPendingQuestion(null);
        }
    }

    async function showSource(message: Message, citation: Citation) {
        if (activeId === null) {
            return;
        }

        const key = `${message.id}:${citation.label}`;
        openSourceKey.current = key;
        sourceOpener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setOpenSource({ conversationId: activeId, messageId: message.id, citation });
        setSourceError(null);
        setSource(sourceCache.current.get(key) ?? null);

        if (sourceCache.current.has(key)) {
            return;
        }

        try {
            const response = await api<{ data: Source }>(`/conversations/${activeId}/messages/${message.id}/sources/${citation.label}`);
            sourceCache.current.set(key, response.data);
            if (openSourceKey.current === key) {
                setSource(response.data);
            }
        } catch (caught) {
            if (openSourceKey.current === key) {
                setSourceError(friendlyError(caught));
            }
        }
    }

    function closeSource() {
        openSourceKey.current = null;
        setOpenSource(null);
        sourceOpener.current?.focus();
    }

    return (
        <AppLayout title="Chat">
            <h1 className="sr-only">Chat</h1>
            <div className={`grid gap-6 ${openSource ? 'lg:grid-cols-[16rem_1fr_22rem]' : 'lg:grid-cols-[16rem_1fr]'}`}>
                <ConversationList
                    conversations={conversations}
                    activeId={activeId}
                    hasMore={hasMoreConversations}
                    isLoading={isLoadingConversations}
                    onSelect={(id) => void selectConversation(id)}
                    onNew={startNewConversation}
                    onLoadMore={() => void loadConversations(conversationPage + 1)}
                />

                <section aria-label="Conversation" className="flex min-h-[60vh] flex-col gap-4">
                    <div className="flex-1">
                        {isLoadingThread ? (
                            <p role="status" className="text-slate-700">
                                Loading conversation…
                            </p>
                        ) : messages.length === 0 && pendingQuestion === null ? (
                            <div className="rounded-lg border border-slate-200 bg-white px-6 py-10 text-center">
                                <h2 className="text-lg font-semibold text-slate-900">Ask the knowledge base</h2>
                                <p className="mt-1 text-slate-700">Answers cite their sources. Select a source label like S1 to read the passage it came from.</p>
                            </div>
                        ) : (
                            <ol role="log" aria-label="Messages" className="flex flex-col gap-4">
                                {messages.map((message) => (
                                    <MessageItem
                                        key={message.id}
                                        message={message}
                                        activeLabel={openSource?.messageId === message.id ? openSource.citation.label : null}
                                        onSelectCitation={(selected, citation) => void showSource(selected, citation)}
                                    />
                                ))}
                                {pendingQuestion !== null && (
                                    <>
                                        <li className="flex justify-end">
                                            <div className="max-w-[85%] rounded-lg bg-blue-800 px-4 py-2 whitespace-pre-wrap text-white">{pendingQuestion}</div>
                                        </li>
                                        <li className="flex">
                                            <p role="status" className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-700">
                                                <span aria-hidden="true" className="size-2 animate-pulse rounded-full bg-blue-700" />
                                                Searching the knowledge base…
                                            </p>
                                        </li>
                                    </>
                                )}
                            </ol>
                        )}
                        <div ref={threadEnd} />
                    </div>

                    {error && (
                        <Alert tone="error" onDismiss={() => setError(null)}>
                            {error}
                        </Alert>
                    )}

                    <ChatInput value={draft} onChange={setDraft} onSubmit={() => void send()} isSending={pendingQuestion !== null} retryIn={retryIn} />
                </section>

                {openSource && (
                    <SourcePanel
                        citation={openSource.citation}
                        source={source}
                        error={sourceError}
                        onClose={closeSource}
                    />
                )}
            </div>
        </AppLayout>
    );
}
