import { useCallback, useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { useInterval } from '../../hooks/useInterval';
import { api } from '../../lib/api';
import { agentKind } from '../../lib/agents';
import Modal from '../common/Modal';
import ChatComposer from '../common/ChatComposer';
import ConsoleHeader from './ConsoleHeader';
import ChatPane from './ChatPane';
import TerminalPane from './TerminalPane';

function ConsoleBody({ sessionId, onClose }) {
    const { state, run, toast } = useApp();
    const [history, setHistory] = useState(null);
    const [tab, setTab] = useState('chat');
    const [sending, setSending] = useState(false);
    const url = `/api/sessions/${sessionId}`;

    const fetchHistory = useCallback(() => api.get(url).then(setHistory, () => null), [url]);

    useEffect(() => {
        fetchHistory();
    }, [fetchHistory]);

    useInterval(fetchHistory, 1800);

    const session = history?.session ?? state.sessions.find((item) => item.id === sessionId);

    if (!session) {
        return null;
    }

    const kind = agentKind(session);

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
            toast('Instruction transmitted');
        } catch {
            toast('Recorded in session history');
        }

        await fetchHistory();
        setSending(false);
    };

    return (
        <>
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
            />
            <div className="ow-body">
                {tab === 'chat' ? (
                    <ChatPane
                        session={session}
                        kind={kind}
                        messages={history?.messages ?? []}
                        terminalOutput={history?.terminal_output ?? ''}
                        working={sending || session.state === 'working'}
                    />
                ) : (
                    <TerminalPane output={history?.terminal_output} />
                )}
            </div>
            <ChatComposer
                placeholder="Send instruction to session… (Enter to transmit, Shift+Enter for newline)"
                submitLabel="TRANSMIT ↵"
                onSubmit={send}
                hint={
                    <>
                        <span>
                            SESSION: <strong>{session.title || session.name}</strong>
                        </span>
                        <span>·</span>
                        <span className="ow-active-model-pill">
                            {kind === 'openrouter' ? 'openrouter // ' : ''}
                            {session.model || 'default'}
                        </span>
                    </>
                }
            />
        </>
    );
}

export default function SessionConsole({ sessionId, onClose }) {
    return (
        <Modal open={Boolean(sessionId)} onClose={onClose} className="openweb-modal">
            <ConsoleBody sessionId={sessionId} onClose={onClose} />
        </Modal>
    );
}
