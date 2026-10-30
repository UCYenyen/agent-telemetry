import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

function signOut() {
    api.post('/logout').finally(() => window.location.assign('/login'));
}

export default function Masthead({ onRefresh, onDeploy }) {
    const { state } = useApp();
    const user = state?.user;
    const isAdmin = Boolean(user?.role === 'admin' || user?.is_admin);

    return (
        <header className="masthead">
            <div>
                <div className="brand-eyebrow">Claude / Antigravity / Concord Multi-Agent Hub / Autonomous LLM Council</div>
                <h1 className="brand-title">
                    Agent <em>Host</em> Command
                </h1>
                <div className="brand-sub">
                    Unified orchestration deck with VPS Shared Memory Group Chat, Local Computer Handoffs, and Role-Based Access Control.
                </div>
            </div>
            <div className="masthead-controls">
                {user?.name && (
                    <span className="concord-device-pill" style={{ padding: '0.45rem 0.7rem' }} title={user.email || ''}>
                        USER: {user.name} [ROLE: {(user.role || 'no_role').toUpperCase()}]
                    </span>
                )}
                <button type="button" className="ghost" onClick={onRefresh}>
                    SYNC TELEMETRY
                </button>
                {isAdmin && (
                    <button type="button" onClick={onDeploy}>
                        + DEPLOY VPS SESSION
                    </button>
                )}
                <button type="button" className="ghost" onClick={signOut}>
                    SIGN OUT
                </button>
            </div>
        </header>
    );
}
