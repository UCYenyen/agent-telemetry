import AgentBadge from '../common/AgentBadge';
import OpenRouterToggle from '../common/OpenRouterToggle';
import ModelPicker from './ModelPicker';

const TABS = [
    ['chat', '💬 OPENWEBUI CHAT'],
    ['console', '💻 LIVE PTY STREAM'],
];

export default function ConsoleHeader({ session, kind, tab, onTabChange, onSelectModel, onToggleOpenRouter, onRefresh, onClear, onClose }) {
    const working = session.state === 'working';

    return (
        <div className="ow-header">
            <div className="ow-left-controls">
                <ModelPicker kind={kind} current={session.model} onSelect={onSelectModel} />
                <OpenRouterToggle on={kind === 'openrouter'} onToggle={onToggleOpenRouter} title="Toggle OpenRouter API for this session" />
                <AgentBadge kind={kind} plain />
                <span className={`state ow-state ${working ? 'working' : 'idle'}`}>
                    <span className="dot" />
                    <span>{working ? 'EXECUTING…' : 'READY'}</span>
                </span>
            </div>

            <div className="ow-right-controls">
                <div className="ow-tab-bar">
                    {TABS.map(([id, label]) => (
                        <button key={id} type="button" className={`ow-tab${tab === id ? ' active' : ''}`} onClick={() => onTabChange(id)}>
                            {label}
                        </button>
                    ))}
                </div>
                <button type="button" className="ow-icon-btn" onClick={onRefresh} title="Sync conversation" aria-label="Sync conversation">
                    ↻
                </button>
                <button type="button" className="ow-icon-btn" onClick={onClear} title="Clear chat history" aria-label="Clear chat history">
                    🗑
                </button>
                <button type="button" className="ow-icon-btn" onClick={onClose} title="Close Console (Esc)" aria-label="Close console">
                    ✕
                </button>
            </div>
        </div>
    );
}
