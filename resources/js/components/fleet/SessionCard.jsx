import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { agentKind } from '../../lib/agents';
import AgentBadge from '../common/AgentBadge';
import OpenRouterToggle from '../common/OpenRouterToggle';
import FailoverBadge from './FailoverBadge';

const STATE_LABELS = { fallback_active: 'FAILOVER ACTIVE', working: 'THINKING & EXECUTING…' };

export default function SessionCard({ session, onOpenConsole }) {
    const { run, toast } = useApp();
    const kind = agentKind(session);
    const url = `/api/sessions/${session.id}`;

    const toggleOpenRouter = (enabled) => {
        toast(`${enabled ? 'Enabling' : 'Disabling'} OpenRouter API for session…`);
        run(() => api.patch(url, { use_openrouter: enabled }), `OpenRouter API ${enabled ? 'ENABLED' : 'DISABLED'} for session`);
    };

    const toggleFailover = () => {
        run(() => api.patch(url, { fallback_enabled: !session.fallback_enabled }), 'Failover configuration updated');
    };

    const stop = () => {
        if (window.confirm(`Terminate session ${session.name}?`)) {
            run(() => api.delete(url), 'Session terminated.');
        }
    };

    return (
        <li className={`session-card agent-${kind}`}>
            <div>
                <div className="session-top">
                    <span className="session-name">{session.title}</span>
                    <AgentBadge kind={kind} label={kind === 'openrouter' ? 'OPENROUTER API' : undefined} />
                    <OpenRouterToggle on={kind === 'openrouter'} onToggle={toggleOpenRouter} title="Toggle whether this agent uses OpenRouter API" />
                    <FailoverBadge session={session} onToggle={toggleFailover} />
                </div>
                <div className="session-specs">
                    <span className={`state ${session.state === 'working' ? 'working' : 'idle'}`}>
                        <span className="dot" /> {STATE_LABELS[session.state] ?? 'READY'}
                    </span>
                    <span className="spec-chip">{session.model || 'default'}</span>
                    <span className="spec-chip">{session.name}</span>
                    {session.messages_count > 0 && (
                        <span className="spec-chip">
                            {session.messages_count} MSG{session.messages_count > 1 ? 'S' : ''}
                        </span>
                    )}
                    {session.last_prompt && (
                        <span className="spec-chip" title={session.last_prompt}>
                            * {session.last_prompt.slice(0, 42)}{session.last_prompt.length > 42 ? '…' : ''}
                        </span>
                    )}
                </div>
            </div>
            <div className="actions">
                <button type="button" className="prompt-trigger" onClick={() => onOpenConsole(session.id)}>
                    AI CLAUDE CODE PROMPT
                </button>
                {session.remote_url && (
                    <a href={session.remote_url} target="_blank" rel="noreferrer" className="btn ghost">
                        REMOTE ↗
                    </a>
                )}
                <button type="button" className="quiet" onClick={stop} title="Terminate session" aria-label="Terminate session">
                    X
                </button>
            </div>
        </li>
    );
}
