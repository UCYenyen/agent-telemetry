import { useEffect, useRef } from 'react';
import { AGENTS } from '../../lib/agents';
import EmptyState from '../common/EmptyState';
import WorkingIndicator from '../common/WorkingIndicator';
import ChatMessage from './ChatMessage';

export default function ChatPane({ session, kind, messages, terminalOutput, telemetry, working, onQuickPrompt }) {
    const pane = useRef(null);

    const cleanResponse = telemetry?.clean_response ?? '';
    const thinkingSteps = telemetry?.thinking_steps ?? [];
    const toolCalls = telemetry?.tool_calls ?? [];
    const currentThought = telemetry?.current_thought || (working ? 'Analyzing codebase & planning next steps…' : 'Ready for instructions');
    const currentAction = telemetry?.current_action || (working ? 'Executing task…' : 'Idle · Context synchronized');

    const hasLiveTurn = Boolean(cleanResponse) || thinkingSteps.length > 0 || toolCalls.length > 0;
    const isEmpty = messages.length === 0 && !hasLiveTurn;

    useEffect(() => {
        if (pane.current) {
            pane.current.scrollTop = pane.current.scrollHeight;
        }
    }, [messages.length, cleanResponse, thinkingSteps.length, toolCalls.length, working]);

    return (
        <div className="ow-chat-pane cc-chat-pane" ref={pane}>
            <div className="cc-telemetry-strip">
                <div className="cc-telemetry-col">
                    <span className="cc-telemetry-label">✻ LIVE AGENT THINKING</span>
                    <span className={`cc-telemetry-val${working ? ' is-pulsing' : ''}`}>{currentThought}</span>
                </div>
                <div className="cc-telemetry-col">
                    <span className="cc-telemetry-label">⏺ CURRENT ACTION / TOOL</span>
                    <span className="cc-telemetry-val mono">{currentAction}</span>
                </div>
                <div className="cc-telemetry-col small">
                    <span className="cc-telemetry-label">ACTIVE ENGINE</span>
                    <span className="cc-telemetry-val mono">{session.model || 'default'}</span>
                </div>
            </div>

            {isEmpty ? (
                <div className="cc-empty-wrap">
                    <EmptyState
                        glyph={AGENTS[kind]?.icon || '✦'}
                        title={session.title || session.name}
                        description={`Claude Code Prompting Interface connected (${kind === 'openrouter' ? 'OpenRouter Cloud API' : 'Live Agent Telemetry Stream'}). Watch live agent thinking, file edits, and tool calls in real time.`}
                    />
                    {onQuickPrompt && (
                        <div className="ow-chips cc-starter-grid">
                            <button type="button" className="ow-chip" onClick={() => onQuickPrompt('Explain your current architecture plan and list any files you are working on.')}>
                                <div className="ow-chip-title">✻ INSPECT THINKING & SCOPE</div>
                                <div className="ow-chip-sub">Ask agent to share its current plan and file claims</div>
                            </button>
                            <button type="button" className="ow-chip" onClick={() => onQuickPrompt('Review recent changes, run tests, and summarize any edge cases.')}>
                                <div className="ow-chip-title">⏺ VERIFY & TEST</div>
                                <div className="ow-chip-sub">Run verification suite and report findings</div>
                            </button>
                        </div>
                    )}
                </div>
            ) : (
                <div className="ow-message-list cc-turn-list">
                    {messages.map((message) => (
                        <ChatMessage key={message.id} message={message} />
                    ))}
                    {hasLiveTurn && (
                        <ChatMessage
                            live={working}
                            avatar="✦"
                            sender={`${(session.title || session.name).toUpperCase()} // LIVE AGENT`}
                            thinkingSteps={thinkingSteps}
                            toolCalls={toolCalls}
                            message={{
                                role: 'assistant',
                                content: cleanResponse,
                                model: session.model,
                            }}
                        />
                    )}
                </div>
            )}
            {working && <WorkingIndicator label={`✻ ${currentThought}`} />}
        </div>
    );
}
