import type { Conversation } from '../types';

interface ConversationListProps {
    conversations: Conversation[];
    activeId: number | null;
    hasMore: boolean;
    isLoading: boolean;
    onSelect: (id: number) => void;
    onNew: () => void;
    onLoadMore: () => void;
}

export default function ConversationList({ conversations, activeId, hasMore, isLoading, onSelect, onNew, onLoadMore }: ConversationListProps) {
    return (
        <nav aria-label="Conversations" className="flex flex-col gap-3">
            <button type="button" onClick={onNew} className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
                New conversation
            </button>
            {conversations.length === 0 && !isLoading ? (
                <p className="text-sm text-slate-700">No conversations yet.</p>
            ) : (
                <ul className="flex flex-col gap-1">
                    {conversations.map((conversation) => (
                        <li key={conversation.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(conversation.id)}
                                aria-current={conversation.id === activeId ? 'true' : undefined}
                                className={`w-full truncate rounded-md px-3 py-2 text-left text-sm ${
                                    conversation.id === activeId ? 'bg-slate-900 font-medium text-white' : 'text-slate-800 hover:bg-slate-200'
                                }`}
                            >
                                {conversation.title}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {isLoading && (
                <p role="status" className="text-sm text-slate-700">
                    Loading conversations…
                </p>
            )}
            {hasMore && !isLoading && (
                <button type="button" onClick={onLoadMore} className="text-sm font-medium text-blue-800 underline underline-offset-2">
                    Load more
                </button>
            )}
        </nav>
    );
}
