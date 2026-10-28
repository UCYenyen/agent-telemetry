import { api } from '../../lib/api';

function signOut() {
    api.post('/logout').finally(() => window.location.assign('/login'));
}

export default function Masthead({ onRefresh, onDeploy }) {
    return (
        <header className="masthead">
            <div>
                <div className="brand-eyebrow">Claude · Antigravity · OpenRouter API · Autonomous LLM Council</div>
                <h1 className="brand-title">
                    Agent <em>Host</em> Command
                </h1>
                <div className="brand-sub">
                    Unified orchestration deck with a dynamic Fallback Chain builder and 5-Advisor Group Deliberation.
                </div>
            </div>
            <div className="masthead-controls">
                <button type="button" className="ghost" onClick={onRefresh}>
                    ↻ SYNC TELEMETRY
                </button>
                <button type="button" onClick={onDeploy}>
                    + DEPLOY SESSION
                </button>
                <button type="button" className="ghost" onClick={signOut}>
                    SIGN OUT
                </button>
            </div>
        </header>
    );
}
