import AgentBadge from '../common/AgentBadge';
import OpenRouterToggle from '../common/OpenRouterToggle';
import ModelPicker from './ModelPicker';

const TABS = [
    ['chat', 'AI CLAUDE CODE UI'],
    ['console', ' RAW INSPECTOR'],
];

export default function ConsoleHeader({
    session,
    kind,
    tab,
    onTabChange,
    onSelectModel,
    onToggleOpenRouter,
    onRefresh,
    onClear,
    onClose,
    sidebarOpen,
    onToggleSidebar,
    telemetry,
}) {
    const working = session.state === 'working' || telemetry?.is_thinking;

    return (
        <div className="ow-header cc-fullscreen-header">
            <div className="ow-left-controls">
                <button
                    type="button"
                    className="cc-back-btn"
                    onClick={onClose}
                    title="Return to Fleet Deck (Esc)"
                >
                    ← FLEET
                </button>
                {onToggleSidebar && (
                    <button
                        type="button"
                        className={`cc-sidebar-toggle${sidebarOpen ? ' active' : ''}`}
                        onClick={onToggleSidebar}
                        title="Toggle Live Fleet Thinking Sidebar"
                    >
                        * AGENTS
                    </button>
                )}
                <div className="cc-session-identity">
                    <span className="cc-session-title">{session.title || session.name}</span>
                    <span className="cc-session-sub">{session.name}</span>
                </div>
                <ModelPicker kind={kind} current={session.model} onSelect={onSelectModel} />
                <OpenRouterToggle on={kind === 'openrouter'} onToggle={onToggleOpenRouter} title="Toggle OpenRouter API for this session" />
                <AgentBadge kind={kind} plain />
                <span className={`state ow-state cc-live-pill ${working ? 'working' : 'idle'}`}>
                    <span className="dot" />
                    <span>{working ? `* ${telemetry?.current_action || 'THINKING…'}` : 'READY'}</span>
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
                    CLEAR
                </button>
                <button type="button" className="ow-icon-btn" onClick={onClose} title="Close Fullscreen (Esc)" aria-label="Close console">
                    X
                </button>
            </div>
        </div>
    );
}
