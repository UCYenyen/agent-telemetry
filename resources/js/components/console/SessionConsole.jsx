import { useCallback, useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { useInterval } from '../../hooks/useInterval';
import { api } from '../../lib/api';
import { AGENTS, agentKind } from '../../lib/agents';
import Modal from '../common/Modal';
import ChatComposer from '../common/ChatComposer';
import ConsoleHeader from './ConsoleHeader';
import ChatPane from './ChatPane';
import TerminalPane from './TerminalPane';

const QUICK_ACTIONS = [
    { icon: '✻', label: 'Show Thinking & Plan', value: 'Walk me through your step-by-step thinking and implementation plan before making edits.' },
    { icon: '⏺', label: 'Summarize Changes', value: 'Summarize the files you have modified and any remaining tasks.' },
    { icon: '⚡', label: 'Run Tests', value: 'Run the test suite and verify everything passes.' },
    { icon: '⇄', label: 'Prepare Handoff', value: 'Prepare a clean handoff summary of your changes and API contracts for the other agents.' },
];

function ConsoleBody({ sessionId, onSelectSession, onClose }) {
    const { state, run, toast } = useApp();
    const [history, setHistory] = useState(null);
    const [tab, setTab] = useState('chat');
    const [sending, setSending] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const url = `/api/sessions/${sessionId}`;

    const fetchHistory = useCallback(() => api.get(url).then(setHistory, () => null), [url]);

    useEffect(() => {
        setHistory(null);
        fetchHistory();
    }, [fetchHistory]);

    useInterval(fetchHistory, 1500);

    const session = history?.session ?? state.sessions.find((item) => item.id === sessionId);

    if (!session) {
        return null;
    }

    const kind = agentKind(session);
    const telemetry = history?.live_telemetry ?? null;
    const working = sending || session.state === 'working' || telemetry?.is_thinking;

    const selectModel = async (model) => {
        toast(`Switching session model to ${model}…`);
        await run(() => api.patch(url, { model }), `Active model set to ${model}`);
        fetchHistory();
    };

    const toggleOpenRouter = async (enabled) => {
        await run(() => api.patch(url, { use_openrouter: enabled }), `OpenRouter API ${enabled ? 'ENABLED' : 'DISABLED'} for session`);
        fetchHistory();
    };

    const clear = async () => {
        if (window.confirm('Clear chat history for this session?')) {
            await run(() => api.delete(`${url}/messages`));
            fetchHistory();
        }
    };

    const send = async (text) => {
        setSending(true);

        try {
            await api.post(`${url}/messages`, { text });
            toast('Prompt transmitted to agent');
        } catch {
            toast('Recorded in session history');
        }

        await fetchHistory();
        setSending(false);
    };

    return (
        <div className="cc-fullscreen-shell">
            <ConsoleHeader
                session={session}
                kind={kind}
                tab={tab}
                onTabChange={setTab}
                onSelectModel={selectModel}
                onToggleOpenRouter={toggleOpenRouter}
                onRefresh={fetchHistory}
                onClear={clear}
                onClose={onClose}
                sidebarOpen={sidebarOpen}
                onToggleSidebar={() => setSidebarOpen((open) => !open)}
                telemetry={telemetry}
            />

            <div className="cc-workspace-split">
                {sidebarOpen && (
                    <aside className="cc-fleet-sidebar">
                        <div className="cc-sidebar-head">
                            <span>✻ LIVE AGENTS ({state.sessions.length})</span>
                        </div>
                        <div className="cc-sidebar-list">
                            {state.sessions.map((item) => {
                                const itemKind = agentKind(item);
                                const isCurrent = item.id === session.id;
                                const isItemWorking = item.state === 'working';

                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        className={`cc-sidebar-item${isCurrent ? ' active' : ''}`}
                                        onClick={() => onSelectSession?.(item.id)}
                                    >
                                        <div className="cc-sidebar-item-top">
                                            <span className="cc-sidebar-icon">{AGENTS[itemKind]?.icon || '✦'}</span>
                                            <span className="cc-sidebar-name">{item.title || item.name}</span>
                                            <span className={`cc-status-dot ${isItemWorking ? 'working' : 'idle'}`} />
                                        </div>
                                        <div className="cc-sidebar-item-meta">
                                            <span>{item.model || 'auto'}</span>
                                            <span>·</span>
                                            <span>{isItemWorking ? '✻ Thinking…' : 'Ready'}</span>
                                        </div>
                                        {item.last_prompt && (
                                            <div className="cc-sidebar-thought">
                                                “{item.last_prompt.slice(0, 56)}{item.last_prompt.length > 56 ? '…' : ''}”
                                            </div>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </aside>
                )}

                <div className="cc-main-column">
                    <div className="ow-body">
                        {tab === 'chat' ? (
                            <ChatPane
                                session={session}
                                kind={kind}
                                messages={history?.messages ?? []}
                                terminalOutput={history?.terminal_output ?? ''}
                                telemetry={telemetry}
                                working={working}
                                onQuickPrompt={send}
                            />
                        ) : (
                            <TerminalPane output={history?.terminal_output} />
                        )}
                    </div>
                    <ChatComposer
                        placeholder="Ask Claude Code / Agent anything, or give instructions… (Enter to send, Shift+Enter for newline)"
                        submitLabel="SEND PROMPT ↵"
                        onSubmit={send}
                        quickActions={QUICK_ACTIONS}
                        hint={
                            <>
                                <span>
                                    AGENT: <strong>{session.title || session.name}</strong>
                                </span>
                                <span>·</span>
                                <span className="ow-active-model-pill">
                                    {kind === 'openrouter' ? 'openrouter // ' : ''}
                                    {session.model || 'default'}
                                </span>
                            </>
                        }
                    />
                </div>
            </div>
        </div>
    );
}

export default function SessionConsole({ sessionId, onSelectSession, onClose }) {
    return (
        <Modal open={Boolean(sessionId)} onClose={onClose} className="openweb-modal cc-fullscreen-modal">
            <ConsoleBody sessionId={sessionId} onSelectSession={onSelectSession} onClose={onClose} />
        </Modal>
    );
}
