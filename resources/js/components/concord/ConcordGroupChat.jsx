import { useEffect, useRef, useState } from 'react';

const GROUP_QUICK_PROMPTS = [
    {
        label: 'Cross-Computer Handoff (User 1 -> User 2)',
        text: 'User 1 agent: implement the backend endpoint on User 1 local computer and publish the payload schema to VPS shared memory. Then communicate with User 2 agent so it updates the React UI on User 2 computer.',
    },
    {
        label: 'Sync Architecture Contract',
        text: 'Review our shared memory bank on the VPS, agree on the WebSocket + REST telemetry contract, and queue the respective file edits on each user local computer.',
    },
    {
        label: 'Audit Cross-Device File Locks',
        text: 'Check active file claims across User 1 and User 2 computers so neither agent edits conflicting files, then propose the implementation split.',
    },
];

function ConcordMessageCard({ message }) {
    const [thoughtOpen, setThoughtOpen] = useState(true);
    const senderKind = message.sender_kind || message.sender_type || 'agent';
    const isHuman = senderKind === 'human';
    const isSystem = senderKind === 'system' || senderKind === 'memory';

    const rawSteps = Array.isArray(message.thinking_steps)
        ? message.thinking_steps
        : message.thinking_text
          ? String(message.thinking_text).split('\n').filter(Boolean)
          : [];
    const tools = Array.isArray(message.tool_calls) ? message.tool_calls : [];

    const localChangesRaw = message.local_changes;
    const localFiles = Array.isArray(localChangesRaw)
        ? localChangesRaw.map((c) => (typeof c === 'string' ? c : c.file)).filter(Boolean)
        : Array.isArray(localChangesRaw?.files)
          ? localChangesRaw.files
          : [];
    const localTargetDevice = (!Array.isArray(localChangesRaw) && localChangesRaw?.executed_on_device)
        || (Array.isArray(localChangesRaw) && localChangesRaw[0]?.device)
        || message.sender_device;
    const localOwner = (!Array.isArray(localChangesRaw) && localChangesRaw?.owner)
        || (Array.isArray(localChangesRaw) && localChangesRaw[0]?.user)
        || message.sender_user
        || message.sender_name;

    const textContent = message.content || message.body || '';

    return (
        <article className={`concord-msg kind-${message.kind || 'chat'} sender-${isHuman ? 'human' : 'agent'}`}>
            <header className="concord-msg-head">
                <div className="concord-msg-identity">
                    <span className={`concord-sender-dot ${isHuman ? 'human' : 'agent'}`} />
                    <strong className="concord-sender-name">{message.sender_name}</strong>
                    {message.sender_device && (
                        <span className="concord-device-pill" title="Prompts & file edits execute on this local computer">
                            PC: {message.sender_device}
                        </span>
                    )}
                    {message.model && (
                        <span className="concord-model-pill">
                            {message.model}
                        </span>
                    )}
                    {message.target_agent && message.target_agent !== 'all' && (
                        <span className="concord-handoff-pill">
                            -&gt; @{message.target_agent}
                        </span>
                    )}
                </div>
                <span className="concord-msg-kind">
                    {isHuman ? 'USER PROMPT' : isSystem ? 'VPS MEMORY SYNC' : message.kind === 'handoff' ? 'AGENT HANDOFF' : 'AGENT + LOCAL EDIT'}
                </span>
            </header>

            {(message.thinking_summary || rawSteps.length > 0) && (
                <div className="cc-thinking-drawer concord-thinking-drawer">
                    <button
                        type="button"
                        className="cc-thinking-toggle"
                        onClick={() => setThoughtOpen((prev) => !prev)}
                    >
                        <span className="cc-thinking-spark">*</span>
                        <span className="cc-thinking-title">
                            {message.thinking_summary || `Thought process (${rawSteps.length} step${rawSteps.length === 1 ? '' : 's'})`}
                        </span>
                        <span className="cc-thinking-chevron">{thoughtOpen ? 'v' : '>'}</span>
                    </button>
                    {thoughtOpen && rawSteps.length > 0 && (
                        <div className="cc-thinking-body">
                            {rawSteps.map((step, index) => (
                                <div key={index} className="cc-thinking-step">
                                    <span className="cc-step-bullet">-</span>
                                    <span className="cc-step-text">{step}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {tools.length > 0 && (
                <div className="cc-tool-list concord-tool-list">
                    {tools.map((item, idx) => (
                        <div key={idx} className="cc-tool-card">
                            <div className="cc-tool-head">
                                <span className="cc-tool-dot">&gt;</span>
                                <span className="cc-tool-name">{item.tool}</span>
                                <span className="cc-tool-summary">{item.target || item.summary}</span>
                            </div>
                            {item.output && (
                                <div className="cc-tool-output">
                                    <span className="cc-tool-branch">|-</span>
                                    <span>{item.output}</span>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {localFiles.length > 0 && (
                <div className="concord-local-edit-banner">
                    <div className="concord-local-edit-head">
                        <span>Local Computer Execution Target: <strong>{localTargetDevice}</strong> ({localOwner})</span>
                        <span className="concord-local-badge">LOCAL WORKSPACE ONLY (NOT VPS)</span>
                    </div>
                    <div className="concord-task-files">
                        {localFiles.map((file, idx) => (
                            <code key={idx} className="concord-file-chip">{file}</code>
                        ))}
                    </div>
                </div>
            )}

            <div className="cc-response-body concord-msg-body" style={{ whiteSpace: 'pre-wrap' }}>
                {textContent}
            </div>
        </article>
    );
}

export default function ConcordGroupChat({
    room,
    canEdit,
    sending,
    onSendPrompt,
    onOpenConnectModal,
    onOpenRolesModal,
}) {
    const participants = room?.participants || [];
    const messages = room?.messages || [];

    const [selectedParticipantId, setSelectedParticipantId] = useState('');
    const [targetAgent, setTargetAgent] = useState('all');
    const [promptText, setPromptText] = useState('');
    const feedRef = useRef(null);

    useEffect(() => {
        if (!selectedParticipantId && participants.length > 0) {
            setSelectedParticipantId(String(participants[0].id));
        }
    }, [participants, selectedParticipantId]);

    useEffect(() => {
        if (feedRef.current) {
            feedRef.current.scrollTop = feedRef.current.scrollHeight;
        }
    }, [messages.length, sending]);

    const activeParticipant = participants.find((p) => String(p.id) === String(selectedParticipantId)) || participants[0] || null;

    const handleSubmit = (event) => {
        event.preventDefault();
        if (!promptText.trim() || !canEdit || sending) return;

        onSendPrompt({
            content: promptText.trim(),
            body: promptText.trim(),
            participant_id: activeParticipant?.id ?? null,
            sender_name: activeParticipant ? `${activeParticipant.user_name}` : 'Room Collaborator',
            sender_email: activeParticipant?.account_email ?? null,
            sender_device: activeParticipant?.device_name ?? 'Local Computer',
            target_agent: targetAgent,
        });
        setPromptText('');
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            handleSubmit(event);
        }
    };

    const projectRole = room?.project_role || 'no_role';

    return (
        <div className="concord-chat-column">
            <div className="concord-roster-strip">
                <div className="concord-roster-head">
                    <span className="concord-section-title">PEOPLE & LOCAL AGENTS IN GROUP CHAT ({participants.length})</span>
                    {canEdit && (
                        <button type="button" className="btn btn-xs btn-emerald" onClick={onOpenConnectModal}>
                            + Connect Another User / Computer
                        </button>
                    )}
                </div>
                <div className="concord-roster-cards">
                    {participants.map((p) => {
                        const isBusy = p.status === 'thinking' || p.status === 'editing_local' || sending;
                        const agentDisplayName = p.name || p.agent_name || 'Local Agent';
                        return (
                            <div key={p.id} className={`concord-participant-card${isBusy ? ' busy' : ''}`}>
                                <div className="concord-p-top">
                                    <span className="concord-p-avatar" style={{ background: p.color || p.avatar_color || '#10b981' }}>
                                        {(p.user_name || 'A').slice(0, 1).toUpperCase()}
                                    </span>
                                    <div className="concord-p-meta">
                                        <div className="concord-p-name">
                                            <strong>{p.user_name}</strong> - <span>{agentDisplayName}</span>
                                        </div>
                                        <div className="concord-p-sub">
                                            PC: {p.device_name} - <code>{p.provider}/{p.model}</code>
                                        </div>
                                    </div>
                                    <span className={`concord-p-status status-${p.status || 'idle'}`}>
                                        {p.status === 'thinking' ? 'THINKING...' : p.status === 'editing_local' ? 'EDITING PC' : 'ONLINE'}
                                    </span>
                                </div>
                                <div className="concord-p-thought">
                                    <span className="cc-thinking-spark">*</span>
                                    <span>{p.current_thought || 'Synced with VPS shared memory bank.'}</span>
                                </div>
                                {Array.isArray(p.local_modified_files) && p.local_modified_files.length > 0 && (
                                    <div className="concord-p-files">
                                        <span className="concord-p-files-label">Local PC edits:</span>
                                        {p.local_modified_files.slice(0, 3).map((file, i) => (
                                            <code key={i} className="concord-file-chip">{file}</code>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="concord-feed" ref={feedRef}>
                {messages.map((msg) => (
                    <ConcordMessageCard key={msg.id} message={msg} />
                ))}

                {sending && (
                    <div className="cc-live-stream-card">
                        <div className="cc-live-stream-head">
                            <span className="cc-spinner" />
                            <span className="cc-live-stream-title">
                                Agents are reasoning on VPS Shared Memory & queueing local file edits for each user computer...
                            </span>
                        </div>
                    </div>
                )}
            </div>

            {canEdit ? (
                <form className="cc-composer-shell concord-composer" onSubmit={handleSubmit}>
                    <div className="concord-composer-controls">
                        <label className="concord-inline-select">
                            <span>Prompting as:</span>
                            <select
                                className="select"
                                value={selectedParticipantId}
                                onChange={(e) => setSelectedParticipantId(e.target.value)}
                            >
                                {participants.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.user_name} ({p.device_name} - {p.name || p.agent_name})
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="concord-inline-select">
                            <span>Talk to / Hand off:</span>
                            <select
                                className="select"
                                value={targetAgent}
                                onChange={(e) => setTargetAgent(e.target.value)}
                            >
                                <option value="all">@All Agents - Collaborate & Handoff Across Computers</option>
                                {participants.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        @{p.name || p.agent_name} ({p.user_name} Computer: {p.device_name})
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>

                    <div className="cc-quick-pills">
                        {GROUP_QUICK_PROMPTS.map((qp) => (
                            <button
                                key={qp.label}
                                type="button"
                                className="cc-quick-pill"
                                onClick={() => setPromptText(qp.text)}
                            >
                                {qp.label}
                            </button>
                        ))}
                    </div>

                    <div className="cc-composer-box">
                        <div className="cc-composer-prompt-icon">&gt;</div>
                        <textarea
                            className="cc-composer-input"
                            rows={2}
                            value={promptText}
                            onChange={(e) => setPromptText(e.target.value)}
                            onKeyDown={handleKeyDown}
                            placeholder="Prompt the group chat... Agent 1 will edit User 1 computer, talk to Agent 2 on VPS shared memory, and Agent 2 will edit User 2 computer."
                        />
                        <button type="submit" className="cc-composer-send" disabled={sending || !promptText.trim()}>
                            {sending ? 'Syncing...' : 'Send [Enter]'}
                        </button>
                    </div>

                    <div className="cc-composer-footer">
                        <span className="cc-composer-hint">
                            <kbd>Enter</kbd> to send - <kbd>Shift + Enter</kbd> for newline
                        </span>
                        <span className="cc-composer-mode">
                            ACTIVE ROLE: {projectRole.toUpperCase()} // VPS Shared Context Window - Local Edits Execute on Each User Computer
                        </span>
                    </div>
                </form>
            ) : (
                <div className="concord-view-only-bar">
                    <div>
                        <strong>VIEW-ONLY ACCESS (PROJECT ROLE: {projectRole})</strong> - Users with <code>no_role</code> can watch the live multi-agent group chat, reasoning traces, and VPS shared memory bank, but cannot send prompts or edit. Ask an <code>admin</code> to set your project role to <code>team_member</code> (or enable Anyone with link can edit).
                    </div>
                    <button type="button" className="btn btn-xs" onClick={onOpenRolesModal}>
                        View Role Permissions
                    </button>
                </div>
            )}
        </div>
    );
}
