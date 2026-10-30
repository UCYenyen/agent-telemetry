import { useEffect, useMemo, useRef, useState } from 'react';

function buildQuickPrompts(participants) {
    const count = participants.length;
    const names = participants.map((p, idx) => `${p.user_name} (${p.device_name || `PC-${idx + 1}`})`).join(' -> ');

    return [
        {
            label: count > 1 ? `Multi-Computer Handoff (${count} MCP Agents)` : 'Plan Local Workspace Changes',
            text: count > 1
                ? `Coordinate a full-stack task across all ${count} connected MCP computers [${names}]: partition the backend, frontend, and test files so each user's agent edits only their own local computer while syncing contracts on the VPS shared memory.`
                : 'Analyze our project architecture in VPS shared memory and queue the initial implementation files on my connected local computer.',
        },
        {
            label: 'Sync Shared API Contract',
            text: 'Review our VPS Shared Memory Bank, agree on the REST + WebSocket schema across all connected MCP agents, and queue the respective file edits on each user computer.',
        },
        {
            label: 'Audit Cross-Device File Locks',
            text: 'Check active file claims across all connected user computers so no two agents edit conflicting files, then propose a non-overlapping task split.',
        },
    ];
}

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
                        <span className="concord-device-pill" title="Prompts & file edits execute on this local computer via MCP">
                            MCP PC: {message.sender_device}
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
                    {isHuman ? 'USER PROMPT' : isSystem ? 'VPS MEMORY SYNC' : message.kind === 'handoff' ? 'MULTI-AGENT HANDOFF' : 'MCP AGENT + LOCAL EDIT'}
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
                            {message.thinking_summary || `Live reasoning trace (${rawSteps.length} step${rawSteps.length === 1 ? '' : 's'})`}
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
                        <span className="concord-local-badge">LOCAL WORKSPACE ONLY (NEVER VPS)</span>
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
    currentUser,
    myParticipants = [],
    hasConnectedMcp = false,
    canEdit,
    isAdmin,
    sending,
    onSendPrompt,
    onDisconnectParticipant,
    onOpenConnectModal,
    onOpenRolesModal,
}) {
    const participants = room?.participants || [];
    const invites = room?.invites || [];
    const messages = room?.messages || [];

    const [selectedParticipantId, setSelectedParticipantId] = useState('');
    const [targetAgent, setTargetAgent] = useState('all');
    const [promptText, setPromptText] = useState('');
    const feedRef = useRef(null);

    useEffect(() => {
        if (myParticipants.length > 0) {
            const existsInMine = myParticipants.some((p) => String(p.id) === String(selectedParticipantId));
            if (!existsInMine) {
                setSelectedParticipantId(String(myParticipants[0].id));
            }
        } else if (participants.length > 0 && !selectedParticipantId) {
            setSelectedParticipantId(String(participants[0].id));
        } else if (participants.length === 0 && selectedParticipantId) {
            setSelectedParticipantId('');
        }
    }, [myParticipants, participants, selectedParticipantId]);

    useEffect(() => {
        if (feedRef.current) {
            feedRef.current.scrollTop = feedRef.current.scrollHeight;
        }
    }, [messages.length, sending]);

    const activeParticipant =
        participants.find((p) => String(p.id) === String(selectedParticipantId))
        || myParticipants[0]
        || null;

    const quickPrompts = useMemo(() => buildQuickPrompts(participants), [participants]);

    const handleSubmit = (event) => {
        event.preventDefault();
        if (!promptText.trim() || !canEdit || !hasConnectedMcp || sending) return;

        onSendPrompt({
            content: promptText.trim(),
            body: promptText.trim(),
            participant_id: activeParticipant?.id ?? null,
            sender_name: activeParticipant?.user_name || currentUser?.name || 'Collaborator',
            sender_email: activeParticipant?.account_email || currentUser?.email || null,
            sender_device: activeParticipant?.device_name || 'Local MCP Computer',
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
    const userEmailLower = String(currentUser?.email || '').trim().toLowerCase();

    return (
        <div className="concord-chat-column">
            {/* Roster Section: Unlimited Invited Users + Connected MCP Nodes */}
            <div className="concord-roster-strip">
                <div className="concord-roster-head">
                    <div className="concord-roster-head-left">
                        <span className="concord-section-title">
                            CONNECTED MCP AGENTS ({participants.length}) // TEAM MEMBERS ({invites.length + 1})
                        </span>
                        <div className="concord-member-pills">
                            <span className="concord-member-chip owner" title="Project Owner / Admin">
                                {room?.owner_email || 'Owner'} [ADMIN]
                            </span>
                            {invites.map((inv) => {
                                const isMcpOnline = participants.some(
                                    (p) => String(p.account_email || '').toLowerCase() === String(inv.email || '').toLowerCase()
                                );
                                const roleLabel = inv.role === 'editor' || inv.role === 'team_member' ? 'team_member' : 'no_role';
                                return (
                                    <span
                                        key={inv.id}
                                        className={`concord-member-chip ${isMcpOnline ? 'mcp-ready' : 'mcp-waiting'}`}
                                        title={`${inv.email} - Role: ${roleLabel} - ${isMcpOnline ? 'MCP Connected' : 'MCP Not Connected Yet'}`}
                                    >
                                        {inv.name || inv.email} [{roleLabel}] {isMcpOnline ? '// MCP ON' : '// NO MCP'}
                                    </span>
                                );
                            })}
                        </div>
                    </div>

                    <div className="concord-roster-actions">
                        <button type="button" className="btn btn-xs" onClick={onOpenRolesModal}>
                            {isAdmin ? '+ Add Users / Manage Roles' : 'View Team & Roles'}
                        </button>
                        {canEdit && (
                            <button type="button" className="btn btn-xs btn-emerald" onClick={onOpenConnectModal}>
                                {hasConnectedMcp ? '+ Connect Another MCP Node' : '+ Connect My MCP First'}
                            </button>
                        )}
                    </div>
                </div>

                {participants.length === 0 ? (
                    <div className="concord-empty-roster-banner">
                        <div>
                            <strong>0 MCP AGENTS CONNECTED IN THIS ROOM</strong> — New group chats start clean with zero default agents. Each user must connect their local MCP agent first to unlock prompting and receive local file edits.
                        </div>
                        {canEdit && (
                            <button type="button" className="btn btn-xs btn-emerald" onClick={onOpenConnectModal}>
                                Connect Your MCP Now
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="concord-roster-cards">
                        {participants.map((p, index) => {
                            const isBusy = p.status === 'thinking' || p.status === 'editing_local' || sending;
                            const agentDisplayName = p.name || p.agent_name || 'Local MCP Agent';
                            const isMine = myParticipants.some((mp) => String(mp.id) === String(p.id))
                                || (userEmailLower && String(p.account_email || '').toLowerCase() === userEmailLower);

                            return (
                                <div key={p.id} className={`concord-participant-card${isBusy ? ' busy' : ''}${isMine ? ' mine' : ''}`}>
                                    <div className="concord-p-top">
                                        <span className="concord-p-avatar" style={{ background: p.color || p.avatar_color || '#10b981' }}>
                                            {`U${index + 1}`}
                                        </span>
                                        <div className="concord-p-meta">
                                            <div className="concord-p-name">
                                                <strong>{p.user_name}</strong> {isMine && <span className="concord-you-tag">[YOU]</span>} - <span>{agentDisplayName}</span>
                                            </div>
                                            <div className="concord-p-sub">
                                                PC: {p.device_name} // <code>{p.provider}/{p.model}</code>
                                            </div>
                                        </div>
                                        <span className={`concord-p-status status-${p.status || 'idle'}`}>
                                            {p.status === 'thinking' ? 'THINKING...' : p.status === 'editing_local' ? 'EDITING PC' : 'MCP READY'}
                                        </span>
                                        {(isAdmin || isMine) && onDisconnectParticipant && (
                                            <button
                                                type="button"
                                                className="concord-p-disconnect"
                                                title="Disconnect this MCP agent"
                                                onClick={() => onDisconnectParticipant(p)}
                                            >
                                                x
                                            </button>
                                        )}
                                    </div>
                                    <div className="concord-p-thought">
                                        <span className="cc-thinking-spark">*</span>
                                        <span>{p.current_thought || 'MCP Handshake verified - synced with VPS shared memory bank.'}</span>
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
                )}
            </div>

            {/* Live Group Chat Feed & Onboarding Empty State */}
            <div className="concord-feed" ref={feedRef}>
                {messages.length === 0 && (
                    <div className="concord-onboarding-card">
                        <div className="concord-modal-kicker">CONCORD MULTI-USER & MULTI-MCP ARCHITECTURE</div>
                        <h3 className="concord-onboarding-title">How Multi-User MCP Collaboration Works</h3>
                        <p className="concord-onboarding-sub">
                            Every group chat starts with <strong>0 default agents</strong> and supports <strong>unlimited users and computers</strong> (2, 3, 5, 10+ simultaneous collaborators).
                        </p>
                        <div className="concord-onboarding-steps">
                            <div className="concord-onboard-step">
                                <span className="concord-onboard-badge">STEP 01</span>
                                <strong>Add Unlimited Team Members</strong>
                                <p>Admin invites any number of users by email or from registered accounts and sets each user role to <code>team_member</code> (can edit) or <code>no_role</code> (view only).</p>
                                <button type="button" className="btn btn-xs" onClick={onOpenRolesModal}>
                                    {isAdmin ? '+ Add / Invite Users' : 'View Project Members'}
                                </button>
                            </div>
                            <div className="concord-onboard-step">
                                <span className="concord-onboard-badge emerald">STEP 02</span>
                                <strong>Connect Your Local MCP First</strong>
                                <p>Before prompting, each user must connect their local MCP (Claude Code, Antigravity, Cursor, or Codex) on their own computer. Prompting stays locked until your MCP is connected.</p>
                                {canEdit && (
                                    <button type="button" className="btn btn-xs btn-emerald" onClick={onOpenConnectModal}>
                                        Connect My Local MCP
                                    </button>
                                )}
                            </div>
                            <div className="concord-onboard-step">
                                <span className="concord-onboard-badge">STEP 03</span>
                                <strong>Prompt & Execute on Local PCs</strong>
                                <p>Once connected, prompts coordinate across all connected agents on the VPS Shared Memory Bank while file edits execute strictly on each user&apos;s own computer.</p>
                            </div>
                        </div>
                    </div>
                )}

                {messages.map((msg) => (
                    <ConcordMessageCard key={msg.id} message={msg} />
                ))}

                {sending && (
                    <div className="cc-live-stream-card">
                        <div className="cc-live-stream-head">
                            <span className="cc-spinner" />
                            <span className="cc-live-stream-title">
                                Agents are reasoning on VPS Shared Memory & queueing local file edits for all {participants.length} connected user computer(s)...
                            </span>
                        </div>
                    </div>
                )}
            </div>

            {/* Bottom Composer Zone: Role Gate -> MCP Connection Gate -> Active Prompt Composer */}
            {!canEdit ? (
                <div className="concord-view-only-bar">
                    <div>
                        <strong>VIEW-ONLY ACCESS (PROJECT ROLE: {projectRole})</strong> — Users with <code>no_role</code> can watch the live multi-agent group chat, reasoning traces, and VPS shared memory bank, but cannot connect an editing MCP or send prompts. Ask an <code>admin</code> to set your project role to <code>team_member</code> (or enable Anyone with link can edit).
                    </div>
                    <button type="button" className="btn btn-xs" onClick={onOpenRolesModal}>
                        View Role Permissions
                    </button>
                </div>
            ) : !hasConnectedMcp ? (
                <div className="concord-mcp-lock-bar">
                    <div className="concord-mcp-lock-info">
                        <div className="concord-mcp-lock-badge">[PROMPTING LOCKED // MCP CONNECTION REQUIRED]</div>
                        <div className="concord-mcp-lock-title">
                            Connect Your Local MCP Agent First Before Prompting
                        </div>
                        <div className="concord-mcp-lock-sub">
                            You have <strong>{projectRole}</strong> edit access, but you have not connected your local MCP agent to this room yet. Connect your MCP (via 1-click verification, <code>.mcp.json</code>, or the 1-line terminal connector) so your prompts can coordinate on VPS Shared Memory and execute file changes on your computer.
                        </div>
                    </div>
                    <div className="concord-mcp-lock-actions">
                        <button type="button" className="btn btn-emerald" onClick={onOpenConnectModal}>
                            Connect My MCP to Unlock Prompting
                        </button>
                    </div>
                </div>
            ) : (
                <form className="cc-composer-shell concord-composer" onSubmit={handleSubmit}>
                    <div className="concord-composer-controls">
                        <label className="concord-inline-select">
                            <span>Prompting via your MCP:</span>
                            <select
                                className="select"
                                value={selectedParticipantId}
                                onChange={(e) => setSelectedParticipantId(e.target.value)}
                            >
                                {(myParticipants.length > 0 ? myParticipants : participants).map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.user_name} // PC: {p.device_name} ({p.name || p.agent_name}) [MCP CONNECTED]
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="concord-inline-select">
                            <span>Target MCP Agents:</span>
                            <select
                                className="select"
                                value={targetAgent}
                                onChange={(e) => setTargetAgent(e.target.value)}
                            >
                                <option value="all">
                                    @All Connected MCP Agents ({participants.length} Computer{participants.length === 1 ? '' : 's'}) - Collaborate & Handoff
                                </option>
                                {participants.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        @{p.name || p.agent_name} ({p.user_name} // PC: {p.device_name})
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>

                    <div className="cc-quick-pills">
                        {quickPrompts.map((qp) => (
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
                            placeholder={`MCP Connected (${activeParticipant?.device_name || 'Local PC'}). Prompt the group chat to coordinate across all ${participants.length} connected user computer(s)...`}
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
                            MCP VERIFIED: {activeParticipant?.device_name} // ROLE: {projectRole.toUpperCase()} // {participants.length} CONNECTED COMPUTER(S)
                        </span>
                    </div>
                </form>
            )}
        </div>
    );
}

