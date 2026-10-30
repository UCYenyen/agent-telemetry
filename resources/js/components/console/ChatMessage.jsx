import { useState } from 'react';
import { formatTime } from '../../lib/agents';

export default function ChatMessage({ message, avatar, sender, thinkingSteps = [], toolCalls = [], live = false }) {
    const isUser = message.role === 'user';
    const [thoughtsOpen, setThoughtsOpen] = useState(true);
    const hasThoughts = Boolean(message.thinking_text) || thinkingSteps.length > 0;
    const hasTools = (message.tool_calls && message.tool_calls.length > 0) || toolCalls.length > 0;
    const effectiveTools = message.tool_calls?.length ? message.tool_calls : toolCalls;
    const effectiveThoughts = thinkingSteps.length
        ? thinkingSteps
        : message.thinking_text
          ? message.thinking_text.split('\n').filter(Boolean)
          : [];

    return (
        <div className={`ow-msg-row cc-turn-row ${isUser ? 'user' : 'assistant'}${live ? ' is-live' : ''}`}>
            <div className={`ow-avatar cc-avatar ${isUser ? 'user' : 'ai'}`}>
                {avatar ?? (isUser ? '❯' : '✦')}
            </div>
            <div className="ow-msg-content cc-turn-content">
                <div className="ow-msg-header cc-turn-header">
                    <span className="cc-sender-name">{sender ?? (isUser ? 'YOU' : 'CLAUDE CODE AGENT')}</span>
                    {message.sender_account && <span className="cc-account-pill">{message.sender_account}</span>}
                    {message.model && <span className="cc-model-tag">// {message.model}</span>}
                    {message.created_at && <span>{formatTime(message.created_at)}</span>}
                    {live && <span className="cc-live-badge">● LIVE TELEMETRY</span>}
                </div>

                {!isUser && hasThoughts && (
                    <div className="cc-thinking-box">
                        <button
                            type="button"
                            className="cc-thinking-toggle"
                            onClick={() => setThoughtsOpen((open) => !open)}
                        >
                            <span className="cc-thinking-star">✻</span>
                            <span className="cc-thinking-title">
                                {live ? 'Agent Thinking Live…' : `Thought process (${effectiveThoughts.length} step${effectiveThoughts.length === 1 ? '' : 's'})`}
                            </span>
                            <span className="cc-thinking-chevron">{thoughtsOpen ? '▾' : '▸'}</span>
                        </button>
                        {thoughtsOpen && (
                            <div className="cc-thinking-body">
                                {effectiveThoughts.map((step, idx) => (
                                    <div key={idx} className="cc-thinking-line">
                                        <span className="cc-thinking-bullet">∴</span>
                                        <span>{step}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {!isUser && hasTools && (
                    <div className="cc-tools-stack">
                        {effectiveTools.map((item, idx) => (
                            <div key={idx} className="cc-tool-card">
                                <div className="cc-tool-head">
                                    <span className="cc-tool-dot">⏺</span>
                                    <span className="cc-tool-name">{item.tool || item.name || 'Tool'}</span>
                                    <code className="cc-tool-target">{item.target || item.detail || ''}</code>
                                </div>
                                {item.output && (
                                    <div className="cc-tool-output">
                                        <span className="cc-tool-elbow">⎿</span>
                                        <pre>{item.output}</pre>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {message.content && (
                    <div className={`ow-bubble cc-bubble ${isUser ? 'user-bubble' : 'ai-bubble'}`}>
                        {message.content}
                    </div>
                )}
            </div>
        </div>
    );
}
