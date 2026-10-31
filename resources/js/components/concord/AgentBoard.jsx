import { useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';

const WORKING_STATUSES = ['working', 'thinking', 'editing_local'];
const WORKING_WINDOW_MS = 5 * 60 * 1000;
const IDLE_WINDOW_MS = 30 * 60 * 1000;

export function useNow(intervalMs = 15000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), intervalMs);
        return () => clearInterval(id);
    }, [intervalMs]);
    return now;
}

export function timeAgo(value, now) {
    if (!value) return '';
    const seconds = Math.max(0, Math.round((now - Date.parse(value)) / 1000));
    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.round(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    return `${Math.round(seconds / 86400)}d ago`;
}

export function presenceOf(participant, now) {
    const seenAgo = participant.last_seen_at ? now - Date.parse(participant.last_seen_at) : Infinity;
    if (WORKING_STATUSES.includes(participant.status) && seenAgo < WORKING_WINDOW_MS) return 'working';
    if (seenAgo < IDLE_WINDOW_MS) return 'idle';
    return 'away';
}

const PRESENCE_LABEL = { working: 'Working', idle: 'Idle', away: 'Away' };
const PRESENCE_ORDER = { working: 0, idle: 1, away: 2 };

const TASK_LABEL = { queued: 'Waiting', running: 'Running', completed: 'Done', failed: 'Failed' };

function AgentCard({ participant, presence, isMine, canDisconnect, onDisconnect, now, selected, onSelect, prompts }) {
    const [promptOpen, setPromptOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const activity = [...(participant.activity || [])].reverse();
    const visibleActivity = historyOpen ? activity : activity.slice(0, 5);
    const files = (participant.local_modified_files || []).slice(-6);
    const prompt = participant.current_prompt || '';
    const longPrompt = prompt.length > 280 || prompt.split('\n').length > 4;

    return (
        <article
            className={`agent-card presence-${presence}${isMine ? ' mine' : ''}${selected ? ' selected' : ''}`}
            aria-label={`${participant.user_name}, ${PRESENCE_LABEL[presence]}`}
        >
            <header className="agent-card-head">
                <span className={`agent-dot ${presence}`} aria-hidden="true" />
                <div className="agent-identity">
                    <div className="agent-name">
                        {participant.user_name}
                        {isMine && <span className="agent-you">you</span>}
                    </div>
                    <div className="agent-sub">
                        {participant.device_name}
                        {participant.provider && participant.provider !== 'mcp' ? ` · ${participant.provider}` : ''}
                    </div>
                </div>
                <div className="agent-state">
                    <span className={`agent-state-label ${presence}`}>{PRESENCE_LABEL[presence]}</span>
                    <span className="agent-seen">{timeAgo(participant.last_seen_at, now)}</span>
                </div>
            </header>

            <section className="agent-block">
                <div className="agent-block-label">
                    Prompt
                    {participant.current_prompt_at && <span className="agent-block-time">{timeAgo(participant.current_prompt_at, now)}</span>}
                </div>
                {prompt ? (
                    <>
                        <p className={`agent-prompt${longPrompt && !promptOpen ? ' clamped' : ''}`}>{prompt}</p>
                        {longPrompt && (
                            <button type="button" className="agent-link" onClick={() => setPromptOpen((open) => !open)}>
                                {promptOpen ? 'Show less' : 'Show full prompt'}
                            </button>
                        )}
                    </>
                ) : (
                    <p className="agent-empty">No prompt reported yet.</p>
                )}
            </section>

            <section className="agent-block">
                <div className="agent-block-label">Now</div>
                <p className="agent-now">
                    {presence === 'working' ? participant.current_action || 'Working…' : presence === 'idle' ? 'Waiting for a prompt' : 'Not active'}
                </p>
            </section>

            {prompts.length > 0 && (
                <section className="agent-block">
                    <div className="agent-block-label">Sent from Concord</div>
                    <ol className="agent-tasks">
                        {prompts.map((task) => (
                            <li key={task.id} className={`agent-task task-${task.status}`}>
                                <div className="agent-task-head">
                                    <span className={`agent-task-status ${task.status}`}>{TASK_LABEL[task.status] || task.status}</span>
                                    <time dateTime={task.created_at}>{timeAgo(task.created_at, now)}</time>
                                </div>
                                <p className="agent-task-text">{task.instructions}</p>
                                {task.reply && <p className="agent-task-reply">{task.reply}</p>}
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {activity.length > 0 && (
                <section className="agent-block">
                    <div className="agent-block-label">Recent activity</div>
                    <ol className="agent-activity">
                        {visibleActivity.map((item, index) => (
                            <li key={`${item.at}-${index}`} className={`activity-${item.type}`}>
                                <span className="activity-text">{item.text}</span>
                                <time className="activity-time" dateTime={item.at}>{timeAgo(item.at, now)}</time>
                            </li>
                        ))}
                    </ol>
                    {activity.length > 5 && (
                        <button type="button" className="agent-link" onClick={() => setHistoryOpen((open) => !open)}>
                            {historyOpen ? 'Show less' : `Show all ${activity.length}`}
                        </button>
                    )}
                </section>
            )}

            {files.length > 0 && (
                <section className="agent-block">
                    <div className="agent-block-label">Files changed</div>
                    <div className="agent-files">
                        {files.map((file) => (
                            <code key={file} className="agent-file">{file}</code>
                        ))}
                    </div>
                </section>
            )}

            <footer className="agent-card-foot">
                {onSelect && (
                    <button type="button" className={`btn btn-sm${selected ? ' btn-primary' : ''}`} aria-pressed={selected} onClick={() => onSelect(selected ? null : participant.id)}>
                        {selected ? 'Prompting this agent' : 'Prompt this agent'}
                    </button>
                )}
                {canDisconnect && (
                    <button type="button" className="agent-link danger" onClick={() => onDisconnect(participant)}>
                        Disconnect
                    </button>
                )}
            </footer>
        </article>
    );
}

// Agent sessions deployed on this VPS (Fleet). Prompts go straight to the session's terminal.
function VpsSessionCard({ session, selected, onSelect, onOpenConsole }) {
    const { run } = useApp();
    const working = session.state === 'working' || session.state === 'fallback_active';
    const presence = working ? 'working' : 'idle';

    const remove = () => {
        if (window.confirm(`Delete the VPS session "${session.title || session.name}"? Its tmux session on the VPS is stopped.`)) {
            if (selected) onSelect?.(null);
            run(() => api.delete(`/api/sessions/${session.id}`), 'Session deleted');
        }
    };

    return (
        <article className={`agent-card presence-${presence}${selected ? ' selected' : ''}`} aria-label={`${session.title}, VPS session, ${PRESENCE_LABEL[presence]}`}>
            <header className="agent-card-head">
                <span className={`agent-dot ${presence}`} aria-hidden="true" />
                <div className="agent-identity">
                    <div className="agent-name">
                        {session.title || session.name}
                        <span className="agent-you">VPS</span>
                    </div>
                    <div className="agent-sub">
                        {session.agent} · {session.model || 'default model'}
                    </div>
                </div>
                <div className="agent-state">
                    <span className={`agent-state-label ${presence}`}>{PRESENCE_LABEL[presence]}</span>
                </div>
            </header>

            <section className="agent-block">
                <div className="agent-block-label">Prompt</div>
                {session.last_prompt ? <p className="agent-prompt clamped">{session.last_prompt}</p> : <p className="agent-empty">No prompt yet.</p>}
            </section>

            <footer className="agent-card-foot">
                {onSelect && (
                    <button type="button" className={`btn btn-sm${selected ? ' btn-primary' : ''}`} aria-pressed={selected} onClick={() => onSelect(selected ? null : `vps:${session.id}`)}>
                        {selected ? 'Prompting this agent' : 'Prompt this agent'}
                    </button>
                )}
                {onOpenConsole && (
                    <button type="button" className="btn btn-sm" onClick={() => onOpenConsole(session.id)}>
                        Open console
                    </button>
                )}
                <button type="button" className="agent-link danger" onClick={remove}>
                    Delete session
                </button>
            </footer>
        </article>
    );
}

export default function AgentBoard({ participants, vpsSessions = [], tasks = [], myParticipantIds, isAdmin, canEdit, onDisconnect, onConnect, onOpenConsole, selectedId, onSelect }) {
    const now = useNow();

    if (participants.length === 0 && vpsSessions.length === 0) {
        return (
            <div className="agent-board-empty">
                <h3>No agents connected yet</h3>
                <p>Connect Claude Code, Antigravity, Codex or Cursor to this room. Each agent&apos;s prompt and live activity will show up here.</p>
                {canEdit && (
                    <button type="button" className="btn btn-primary" onClick={onConnect}>
                        Connect an agent
                    </button>
                )}
            </div>
        );
    }

    const cards = participants
        .map((participant) => ({ participant, presence: presenceOf(participant, now), isMine: myParticipantIds.has(String(participant.id)) }))
        .sort((a, b) => PRESENCE_ORDER[a.presence] - PRESENCE_ORDER[b.presence] || Number(b.isMine) - Number(a.isMine));

    return (
        <div className="agent-board">
            {vpsSessions.map((session) => (
                <VpsSessionCard
                    key={session.id}
                    session={session}
                    selected={String(selectedId) === `vps:${session.id}`}
                    onSelect={onSelect}
                    onOpenConsole={onOpenConsole}
                />
            ))}
            {cards.map(({ participant, presence, isMine }) => (
                <AgentCard
                    key={participant.id}
                    participant={participant}
                    presence={presence}
                    isMine={isMine}
                    canDisconnect={Boolean(onDisconnect) && (isAdmin || isMine)}
                    onDisconnect={onDisconnect}
                    now={now}
                    selected={String(selectedId) === String(participant.id)}
                    onSelect={canEdit ? onSelect : null}
                    prompts={tasks.filter((task) => task.kind === 'prompt' && String(task.target_participant_id) === String(participant.id)).slice(0, 3)}
                />
            ))}
        </div>
    );
}
