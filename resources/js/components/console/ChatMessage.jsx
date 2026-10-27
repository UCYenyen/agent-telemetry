import { formatTime } from '../../lib/agents';

export default function ChatMessage({ message, avatar, sender }) {
    const isUser = message.role === 'user';

    return (
        <div className={`ow-msg-row ${isUser ? 'user' : 'assistant'}`}>
            <div className={`ow-avatar ${isUser ? 'user' : 'ai'}`}>{avatar ?? (isUser ? 'OP' : 'AI')}</div>
            <div className="ow-msg-content">
                <div className="ow-msg-header">
                    <span>{sender ?? (isUser ? 'OPERATOR' : 'AGENT')}</span>
                    {message.model && <span>// {message.model}</span>}
                    <span>{formatTime(message.created_at)}</span>
                </div>
                <div className="ow-bubble">{message.content}</div>
            </div>
        </div>
    );
}
