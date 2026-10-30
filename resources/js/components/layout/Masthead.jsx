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
                <h1 className="brand-title">
                    Agent <em>Host</em> Command
                </h1>
                <div className="brand-sub">
                    Run Claude, Antigravity and OpenRouter agents on the VPS, coordinate them in group chat, and put questions to the council.
                </div>
            </div>
            <div className="masthead-controls">
                {user?.name && (
                    <span className="user-pill" title={user.email || ''}>
                        {user.name} <strong>{(user.role || 'no_role').replace('_', ' ')}</strong>
                    </span>
                )}
                <button type="button" className="ghost" onClick={onRefresh}>
                    Refresh
                </button>
                {isAdmin && (
                    <button type="button" onClick={onDeploy}>
                        Deploy session
                    </button>
                )}
                <button type="button" className="ghost" onClick={signOut}>
                    Sign out
                </button>
            </div>
        </header>
    );
}
