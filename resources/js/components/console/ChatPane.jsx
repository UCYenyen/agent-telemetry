import { useEffect, useRef } from 'react';
import { AGENTS } from '../../lib/agents';
import EmptyState from '../common/EmptyState';
import WorkingIndicator from '../common/WorkingIndicator';
import ChatMessage from './ChatMessage';

export default function ChatPane({ session, kind, messages, terminalOutput, working }) {
    const pane = useRef(null);
    const terminalTail = terminalOutput.trim().split('\n').slice(-25).join('\n');
    const isEmpty = messages.length === 0 && !terminalTail;

    useEffect(() => {
        pane.current.scrollTop = pane.current.scrollHeight;
    }, [messages.length, terminalTail, working]);

    return (
        <div className="ow-chat-pane" ref={pane}>
            {isEmpty ? (
                <EmptyState
                    glyph={AGENTS[kind].icon}
                    title={session.title || session.name}
                    description={`OpenWebUI Command Bridge connected (${kind === 'openrouter' ? 'OpenRouter API Direct Mode' : 'CLI / PTY Mode'}). Select any Claude, Gemini, or OpenRouter model from the top bar.`}
                />
            ) : (
                <div className="ow-message-list">
                    {messages.map((message) => (
                        <ChatMessage key={message.id} message={message} />
                    ))}
                    {terminalTail && <ChatMessage message={{ role: 'assistant', content: terminalTail, model: session.model }} avatar="PTY" sender="LIVE AGENT STREAM" />}
                </div>
            )}
            {working && <WorkingIndicator label="AGENT EXECUTING TASK…" />}
        </div>
    );
}
