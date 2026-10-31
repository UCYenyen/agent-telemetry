import { useEffect, useState } from 'react';
import { useEscapeKey } from '../../hooks/useEscapeKey';
import { useApp } from '../../context/AppContext';

const slug = (value) => String(value || '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '');

export default function ConnectDeviceModal({ open, room, currentUser, visitorEmail, myParticipants = [], onClose }) {
    const { toast } = useApp();
    const [userName, setUserName] = useState('');
    const [accountEmail, setAccountEmail] = useState('');
    const [deviceName, setDeviceName] = useState('');
    const [configClient, setConfigClient] = useState('claude');

    useEffect(() => {
        if (!open) return;
        // Prefill from a computer you already connected, never from the group's own VPS session.
        const mine = myParticipants.find((p) => !p.agent_session_id);
        const name = currentUser?.name || currentUser?.username || '';
        setUserName(mine?.user_name || name);
        setAccountEmail(mine?.account_email || currentUser?.email || visitorEmail || room?.viewer_email || '');
        setDeviceName(mine?.device_name || `${slug(currentUser?.username || name) || 'my'}-laptop`);
    }, [open, currentUser, visitorEmail, room?.viewer_email, myParticipants]);

    useEscapeKey(open && room, onClose);

    if (!open || !room) {
        return null;
    }

    const baseUrl = window.location.origin;
    const connectQuery = new URLSearchParams();
    if (accountEmail) connectQuery.set('email', accountEmail);
    if (userName) connectQuery.set('user', userName);
    const oneLineCommand = `curl -fsSL "${baseUrl}/connect/${room.share_token}${connectQuery.toString() ? `?${connectQuery}` : ''}" | bash`;

    const mcpBaseEndpoint = room.mcp_endpoint || `${baseUrl}/api/concord/mcp/${room.mcp_token}`;
    const mcpUrl = `${mcpBaseEndpoint}?${new URLSearchParams({ user_email: accountEmail, user_name: userName, device_name: deviceName })}`;
    const mcpJson = (server) => JSON.stringify({ mcpServers: { concord: server } }, null, 2);
    const clientConfigs = {
        claude: {
            label: 'Claude Code',
            hint: 'MCP only. The one-line connector above also adds the hooks that show your prompts on the board, brief Claude on what teammates are doing, and run tasks sent from this group.',
            text: `claude mcp add --transport http concord "${mcpUrl}"`,
        },
        antigravity: {
            label: 'Antigravity',
            hint: 'Antigravity CLI: run the command. Antigravity app: Agent panel > MCP Servers > Manage > View raw config.',
            text: `agy mcp add concord "${mcpUrl}"\n\n# or mcp_config.json\n${mcpJson({ serverUrl: mcpUrl })}`,
        },
        gemini: {
            label: 'Gemini CLI',
            hint: 'Add to .gemini/settings.json in the project or ~/.gemini/settings.json.',
            text: mcpJson({ httpUrl: mcpUrl }),
        },
        codex: {
            label: 'Codex',
            hint: 'Run once; Codex stores it in ~/.codex/config.toml.',
            text: `codex mcp add concord --url "${mcpUrl}"`,
        },
        cursor: {
            label: 'Cursor',
            hint: 'Save as .cursor/mcp.json in the project or ~/.cursor/mcp.json.',
            text: mcpJson({ url: mcpUrl }),
        },
        other: {
            label: 'Other',
            hint: 'For clients that only launch local commands, such as Claude Desktop.',
            text: mcpJson({ command: 'npx', args: ['-y', 'mcp-remote', mcpUrl] }),
        },
    };
    const activeConfig = clientConfigs[configClient] || clientConfigs.claude;

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal connect-modal" role="dialog" aria-modal="true" aria-labelledby="concord-connect-title" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <h3 className="concord-modal-title" id="concord-connect-title">Connect an agent to {room.title}</h3>
                    <button type="button" className="btn" onClick={onClose}>Close</button>
                </div>

                <p className="connect-intro">
                    This link belongs to this group only. Agents you connect here see this group&apos;s chat and shared memory, and nothing from other groups.
                </p>

                <div className="connect-fields">
                    <label className="field">
                        <span>Your name</span>
                        <input className="input" value={userName} onChange={(e) => setUserName(e.target.value)} />
                    </label>
                    <label className="field">
                        <span>Your email</span>
                        <input className="input" type="email" value={accountEmail} onChange={(e) => setAccountEmail(e.target.value)} placeholder="you@example.com" />
                    </label>
                </div>
                <p className="connect-note">Use the email you sign in to Agent Host with, so prompts you send from this group run on your agent.</p>

                <section className="connect-step">
                    <div className="connect-step-head">
                        <h4>1. Run this in your project folder</h4>
                        <button type="button" className="btn btn-sm btn-primary" onClick={() => copyText(oneLineCommand, 'Copied the connector command')}>
                            Copy
                        </button>
                    </div>
                    <pre className="concord-code-pre">{oneLineCommand}</pre>
                    <p className="connect-note">
                        Sets up Claude Code (terminal and VS Code), Cursor, Gemini CLI and Antigravity in that project. Needs bash, curl and python3; on Windows run it in Git Bash. Then restart Claude Code or reload the VS Code window.
                    </p>
                </section>

                <section className="connect-step">
                    <div className="connect-step-head">
                        <h4>Or add the MCP server by hand</h4>
                        <button type="button" className="btn btn-sm" onClick={() => copyText(activeConfig.text, `Copied ${activeConfig.label} config`)}>
                            Copy
                        </button>
                    </div>
                    <label className="field connect-device">
                        <span>This computer&apos;s name</span>
                        <input className="input" value={deviceName} onChange={(e) => setDeviceName(e.target.value)} />
                    </label>
                    <div className="concord-client-tabs" role="tablist" aria-label="Agent client">
                        {Object.entries(clientConfigs).map(([id, config]) => (
                            <button
                                key={id}
                                type="button"
                                role="tab"
                                aria-selected={configClient === id}
                                className={`concord-client-tab${configClient === id ? ' active' : ''}`}
                                onClick={() => setConfigClient(id)}
                            >
                                {config.label}
                            </button>
                        ))}
                    </div>
                    <p className="concord-client-hint">{activeConfig.hint}</p>
                    <pre className="concord-code-pre">{activeConfig.text}</pre>
                </section>
            </div>
        </div>
    );
}
