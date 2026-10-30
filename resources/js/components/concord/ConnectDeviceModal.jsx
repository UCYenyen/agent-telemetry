import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

export default function ConnectDeviceModal({ open, room, activeToken, onClose, onConnected }) {
    const { toast } = useApp();
    const [userName, setUserName] = useState('User 2 (Collaborator)');
    const [accountEmail, setAccountEmail] = useState('partner@account.dev');
    const [deviceName, setDeviceName] = useState('Partner-Laptop (Local)');
    const [agentName, setAgentName] = useState('Partner Local Agent');
    const [provider, setProvider] = useState('claude');
    const [model, setModel] = useState('claude-sonnet-5-5-high');
    const [roleTitle, setRoleTitle] = useState('Full-Stack & Local Workspace Lead');
    const [submitting, setSubmitting] = useState(false);
    const [selectedParticipantId, setSelectedParticipantId] = useState(room?.participants?.[0]?.id ?? '');

    if (!open || !room) {
        return null;
    }

    const token = activeToken || room.share_token;
    const baseUrl = window.location.origin;
    const participantQuery = selectedParticipantId ? `?participant_id=${selectedParticipantId}` : '';
    const oneLineCommand = `curl -fsSL "${baseUrl}/connect/${room.share_token}${participantQuery}" | bash`;
    const mcpEndpoint = room.mcp_endpoint || `${baseUrl}/api/concord/mcp/${room.mcp_token}`;
    const mcpConfigJson = JSON.stringify({
        mcpServers: {
            'concord-vps-shared-memory': {
                type: 'http',
                url: mcpEndpoint,
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

    const handleRegisterDevice = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        try {
            const res = await api.post(`/api/concord/shared/${token}/register-device`, {
                user_name: userName,
                account_email: accountEmail,
                device_name: deviceName,
                agent_name: agentName,
                name: agentName,
                provider,
                model,
                role_title: roleTitle,
            });
            setSelectedParticipantId(res.participant.id);
            toast(`Connected ${res.participant.device_name} (${res.participant.name || res.participant.agent_name}) to VPS Shared Memory!`);
            onConnected?.(res.participant);
        } catch (err) {
            toast(err.message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal concord-modal-wide" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <div>
                        <div className="concord-modal-kicker">VPS SHARED MEMORY + LOCAL COMPUTER EXECUTION</div>
                        <h3 className="concord-modal-title">Connect Your Local Computer & AI Agent</h3>
                    </div>
                    <button type="button" className="btn" onClick={onClose}>Close</button>
                </div>

                <div className="concord-connect-architecture">
                    <div className="concord-arch-pill">
                        <strong>ON THIS VPS ({window.location.hostname}):</strong> Shared Context Memory, Context Window, File Locks, & Multi-Agent Group Chat
                    </div>
                    <div className="concord-arch-pill emerald">
                        <strong>ON EACH USER COMPUTER:</strong> Local Agent Prompting & Real Workspace File Edits (User 1 agent edits User 1 computer; User 2 agent edits User 2 computer)
                    </div>
                </div>

                <div className="concord-connect-grid">
                    <form className="concord-connect-card" onSubmit={handleRegisterDevice}>
                        <div className="concord-label">STEP 1 // 1-CLICK REGISTER YOUR LOCAL COMPUTER & AGENT</div>
                        <div className="concord-form-grid">
                            <label className="field">
                                <span>Your Name / Account</span>
                                <input className="input" value={userName} onChange={(e) => setUserName(e.target.value)} required />
                            </label>
                            <label className="field">
                                <span>Account Email</span>
                                <input className="input" type="email" value={accountEmail} onChange={(e) => setAccountEmail(e.target.value)} required />
                            </label>
                            <label className="field">
                                <span>Local Computer Hostname</span>
                                <input className="input" value={deviceName} onChange={(e) => setDeviceName(e.target.value)} placeholder="e.g. Bryan-MacBook-Pro" required />
                            </label>
                            <label className="field">
                                <span>Agent Display Name</span>
                                <input className="input" value={agentName} onChange={(e) => setAgentName(e.target.value)} required />
                            </label>
                            <label className="field">
                                <span>Local CLI / Provider</span>
                                <select className="select" value={provider} onChange={(e) => setProvider(e.target.value)}>
                                    <option value="claude">Claude Code (local CLI)</option>
                                    <option value="antigravity">Antigravity (local CLI)</option>
                                    <option value="codex">OpenAI Codex CLI</option>
                                    <option value="cursor">Cursor Agent</option>
                                </select>
                            </label>
                            <label className="field">
                                <span>Model Identifier</span>
                                <input className="input" value={model} onChange={(e) => setModel(e.target.value)} required />
                            </label>
                        </div>
                        <label className="field" style={{ marginTop: '0.6rem' }}>
                            <span>Workspace Role / Focus</span>
                            <input className="input" value={roleTitle} onChange={(e) => setRoleTitle(e.target.value)} />
                        </label>
                        <button type="submit" className="btn btn-emerald" style={{ marginTop: '0.85rem', width: '100%' }} disabled={submitting}>
                            1-Button Connect Local Computer to Group Chat
                        </button>
                    </form>

                    <div className="concord-connect-card">
                        <div className="concord-label">STEP 2 // RUN 1-LINE CONNECTOR OR REMOTE MCP ON YOUR COMPUTER</div>

                        <label className="field" style={{ marginBottom: '0.65rem' }}>
                            <span>Target Registered Computer</span>
                            <select
                                className="select"
                                value={selectedParticipantId}
                                onChange={(e) => setSelectedParticipantId(e.target.value)}
                            >
                                {(room.participants || []).map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.user_name} - {p.device_name} ({p.name || p.agent_name})
                                    </option>
                                ))}
                            </select>
                        </label>

                        <div className="concord-code-box">
                            <div className="concord-code-head">
                                <span>OPTION A: 1-LINE LOCAL DEVICE CONNECTOR (EXECUTES EDITS ON YOUR PC)</span>
                                <button
                                    type="button"
                                    className="btn btn-xs btn-primary"
                                    onClick={() => copyText(oneLineCommand, 'Copied 1-line local connector command!')}
                                >
                                    Copy Command
                                </button>
                            </div>
                            <pre className="concord-code-pre">{oneLineCommand}</pre>
                        </div>

                        <div className="concord-code-box" style={{ marginTop: '0.75rem' }}>
                            <div className="concord-code-head">
                                <span>OPTION B: REMOTE MCP (.mcp.json FOR CLAUDE CODE / ANTIGRAVITY / CURSOR)</span>
                                <button
                                    type="button"
                                    className="btn btn-xs"
                                    onClick={() => copyText(mcpConfigJson, 'Copied Remote MCP configuration!')}
                                >
                                    Copy .mcp.json
                                </button>
                            </div>
                            <pre className="concord-code-pre">{mcpConfigJson}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
