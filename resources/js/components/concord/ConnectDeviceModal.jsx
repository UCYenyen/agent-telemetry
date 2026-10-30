import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { useEscapeKey } from '../../hooks/useEscapeKey';
import { useApp } from '../../context/AppContext';

export default function ConnectDeviceModal({
    open,
    room,
    currentUser,
    visitorEmail,
    myParticipants = [],
    activeToken,
    onClose,
    onDisconnectParticipant,
    onConnected,
}) {
    const { toast } = useApp();

    const defaultName = currentUser?.name || currentUser?.username || 'Collaborator';
    const defaultEmail = currentUser?.email || visitorEmail || room?.viewer_email || '';
    const defaultSlug = String(currentUser?.username || defaultName || 'user')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '') || 'user';

    const [userName, setUserName] = useState(defaultName);
    const [accountEmail, setAccountEmail] = useState(defaultEmail);
    const [deviceName, setDeviceName] = useState(`${defaultSlug}-PC`);
    const [agentName, setAgentName] = useState(`${defaultName} MCP Agent`);
    const [provider, setProvider] = useState('claude');
    const [model, setModel] = useState('claude-sonnet-5-5-high');
    const [roleTitle, setRoleTitle] = useState('Full-Stack & Local Workspace Lead');
    const [submitting, setSubmitting] = useState(false);
    const [handshakeResult, setHandshakeResult] = useState(null);
    const [selectedParticipantId, setSelectedParticipantId] = useState('');

    useEffect(() => {
        if (!open) return;
        const nextName = currentUser?.name || currentUser?.username || 'Collaborator';
        const nextEmail = currentUser?.email || visitorEmail || room?.viewer_email || '';
        const nextSlug = String(currentUser?.username || nextName || 'user')
            .replace(/[^a-zA-Z0-9]+/g, '-')
            .replace(/^-|-$/g, '') || 'user';

        if (myParticipants.length > 0) {
            const mine = myParticipants[0];
            setUserName(mine.user_name || nextName);
            setAccountEmail(mine.account_email || nextEmail);
            setDeviceName(mine.device_name || `${nextSlug}-PC`);
            setAgentName(mine.name || `${nextName} MCP Agent`);
            setProvider(mine.provider || 'claude');
            setModel(mine.model || 'claude-sonnet-5-5-high');
            setSelectedParticipantId(String(mine.id));
        } else {
            setUserName(nextName);
            setAccountEmail(nextEmail);
            setDeviceName(`${nextSlug}-PC`);
            setAgentName(`${nextName} MCP Agent`);
            setSelectedParticipantId('');
        }
    }, [open, currentUser, visitorEmail, room?.viewer_email, myParticipants]);

    useEscapeKey(open && room, onClose);

    if (!open || !room) {
        return null;
    }

    const token = activeToken || room.share_token;
    const baseUrl = window.location.origin;
    const queryParams = new URLSearchParams();
    if (selectedParticipantId) queryParams.set('participant_id', selectedParticipantId);
    if (accountEmail) queryParams.set('email', accountEmail);
    if (userName) queryParams.set('user', userName);
    const querySuffix = queryParams.toString() ? `?${queryParams.toString()}` : '';

    const oneLineCommand = `curl -fsSL "${baseUrl}/connect/${room.share_token}${querySuffix}" | bash`;

    const mcpBaseEndpoint = room.mcp_endpoint || `${baseUrl}/api/concord/mcp/${room.mcp_token}`;
    const mcpQuery = new URLSearchParams({
        user_email: accountEmail || '',
        user_name: userName || '',
        device_name: deviceName || '',
    }).toString();
    const personalizedMcpUrl = `${mcpBaseEndpoint}?${mcpQuery}`;

    const mcpConfigJson = JSON.stringify({
        mcpServers: {
            'concord-vps-shared-memory': {
                type: 'http',
                url: personalizedMcpUrl,
            },
        },
    }, null, 2);

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    const handleVerifyAndConnectMcp = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        try {
            // 1. Live JSON-RPC 2.0 MCP Handshake (initialize)
            const mcpInit = await api.post(`/api/concord/mcp/${room.mcp_token}`, {
                jsonrpc: '2.0',
                id: 1,
                method: 'initialize',
                params: {
                    user_email: accountEmail,
                    user_name: userName,
                    device_name: deviceName,
                    provider,
                    model,
                },
            });

            // 2. Register / Activate MCP Device in Room
            const res = await api.post(`/api/concord/shared/${token}/register-device`, {
                participant_id: selectedParticipantId || undefined,
                user_name: userName,
                account_email: accountEmail,
                device_name: deviceName,
                agent_name: agentName,
                name: agentName,
                provider,
                model,
                role_title: roleTitle,
            });

            setSelectedParticipantId(String(res.participant.id));
            setHandshakeResult({
                protocolVersion: mcpInit?.result?.protocolVersion || res.mcp_handshake?.protocolVersion || '2024-11-05',
                serverName: mcpInit?.result?.serverInfo?.name || 'concord-vps-shared-memory-hub',
                participant: res.participant,
                tools: res.mcp_handshake?.tools || [
                    'get_shared_memory',
                    'write_shared_memory',
                    'send_group_message',
                    'report_thinking',
                    'pull_local_tasks',
                ],
            });
            toast(`MCP Connected & Verified for ${res.participant.user_name} (${res.participant.device_name})! Prompting is now unlocked.`);
            onConnected?.(res.participant);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setSubmitting(false);
        }
    };

    const participants = room.participants || [];

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal concord-modal-wide" role="dialog" aria-modal="true" aria-labelledby="concord-connect-title" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <div>
                        <h3 className="concord-modal-title" id="concord-connect-title">Connect your local MCP agent</h3>
                    </div>
                    <button type="button" className="btn" onClick={onClose}>Close</button>
                </div>

                <div className="concord-connect-architecture">
                    <div className="concord-arch-pill">
                        <strong>WHY MCP CONNECTION IS REQUIRED FIRST:</strong> Group Chat rooms start with 0 default agents. Each user connects their own MCP first so prompts know which local computer to target.
                    </div>
                    <div className="concord-arch-pill emerald">
                        <strong>UNLIMITED USERS & COMPUTERS:</strong> 1, 2, 3, 5, or 10+ users can connect their MCP simultaneously. VPS holds shared memory; file changes execute strictly on each user&apos;s own PC.
                    </div>
                </div>

                {handshakeResult && (
                    <div className="concord-mcp-verified-banner">
                        <div>
                            <strong>[MCP HANDSHAKE VERIFIED // PROTOCOL {handshakeResult.protocolVersion}]</strong> Connected <code>{handshakeResult.participant.name}</code> on <code>{handshakeResult.participant.device_name}</code> to <code>{handshakeResult.serverName}</code>. Group Chat prompting is now unlocked!
                        </div>
                        <button type="button" className="btn btn-xs btn-emerald" onClick={onClose}>
                            Start Prompting in Group Chat
                        </button>
                    </div>
                )}

                <div className="concord-connect-grid">
                    <form className="concord-connect-card" onSubmit={handleVerifyAndConnectMcp}>
                        <div className="concord-label">STEP 1 // CONFIGURE & VERIFY YOUR MCP AGENT CONNECTION</div>
                        <div className="concord-form-grid">
                            <label className="field">
                                <span>Your User Name</span>
                                <input className="input" value={userName} onChange={(e) => setUserName(e.target.value)} required />
                            </label>
                            <label className="field">
                                <span>Your Account Email</span>
                                <input className="input" type="email" value={accountEmail} onChange={(e) => setAccountEmail(e.target.value)} placeholder="you@domain.com" required />
                            </label>
                            <label className="field">
                                <span>Local Computer Hostname</span>
                                <input className="input" value={deviceName} onChange={(e) => setDeviceName(e.target.value)} placeholder="e.g. Bryan-MacBook-Pro" required />
                            </label>
                            <label className="field">
                                <span>MCP Agent Display Name</span>
                                <input className="input" value={agentName} onChange={(e) => setAgentName(e.target.value)} required />
                            </label>
                            <label className="field">
                                <span>Local MCP Client / CLI</span>
                                <select className="select" value={provider} onChange={(e) => setProvider(e.target.value)}>
                                    <option value="claude">Claude Code (MCP + Local CLI)</option>
                                    <option value="antigravity">Antigravity (MCP + Local CLI)</option>
                                    <option value="cursor">Cursor IDE (Remote MCP)</option>
                                    <option value="codex">OpenAI Codex CLI</option>
                                </select>
                            </label>
                            <label className="field">
                                <span>Model Identifier</span>
                                <input className="input" value={model} onChange={(e) => setModel(e.target.value)} required />
                            </label>
                        </div>
                        <label className="field" style={{ marginTop: '0.6rem' }}>
                            <span>Workspace Role / Responsibility</span>
                            <input className="input" value={roleTitle} onChange={(e) => setRoleTitle(e.target.value)} />
                        </label>
                        <button type="submit" className="btn btn-emerald" style={{ marginTop: '0.85rem', width: '100%' }} disabled={submitting}>
                            {submitting ? 'Verifying MCP Handshake...' : 'Verify MCP Handshake & Unlock Prompting'}
                        </button>
                    </form>

                    <div className="concord-connect-card">
                        <div className="concord-label">STEP 2 // LINK YOUR LOCAL IDE / TERMINAL VIA MCP OR 1-LINE SCRIPT</div>

                        <div className="concord-code-box">
                            <div className="concord-code-head">
                                <span>OPTION A: REMOTE MCP (.mcp.json FOR CLAUDE CODE / ANTIGRAVITY / CURSOR)</span>
                                <button
                                    type="button"
                                    className="btn btn-xs btn-primary"
                                    onClick={() => copyText(mcpConfigJson, 'Copied personalized .mcp.json configuration!')}
                                >
                                    Copy .mcp.json
                                </button>
                            </div>
                            <pre className="concord-code-pre">{mcpConfigJson}</pre>
                        </div>

                        <div className="concord-code-box" style={{ marginTop: '0.75rem' }}>
                            <div className="concord-code-head">
                                <span>OPTION B: 1-LINE TERMINAL MCP + LOCAL EDIT CONNECTOR</span>
                                <button
                                    type="button"
                                    className="btn btn-xs btn-emerald"
                                    onClick={() => copyText(oneLineCommand, 'Copied 1-line MCP terminal connector command!')}
                                >
                                    Copy Terminal Command
                                </button>
                            </div>
                            <pre className="concord-code-pre">{oneLineCommand}</pre>
                        </div>

                        {participants.length > 0 && (
                            <div style={{ marginTop: '0.85rem' }}>
                                <div className="concord-label">ACTIVE MCP NODES IN THIS ROOM ({participants.length})</div>
                                <div className="concord-people-list" style={{ marginBottom: 0 }}>
                                    {participants.map((p, idx) => (
                                        <div key={p.id} className="concord-person-row">
                                            <div className="concord-person-avatar" style={{ background: p.color || '#10b981', color: '#05120d' }}>
                                                {`U${idx + 1}`}
                                            </div>
                                            <div className="concord-person-info">
                                                <div className="concord-person-name">{p.user_name} — {p.name}</div>
                                                <div className="concord-person-email">PC: {p.device_name} // {p.account_email || 'MCP Verified'}</div>
                                            </div>
                                            {onDisconnectParticipant && (
                                                <button
                                                    type="button"
                                                    className="btn btn-xs btn-danger"
                                                    onClick={() => onDisconnectParticipant(p)}
                                                >
                                                    Disconnect
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

