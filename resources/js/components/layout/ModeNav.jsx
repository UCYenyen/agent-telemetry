import { useApp } from '../../context/AppContext';

export default function ModeNav({ surface, onChange }) {
    const { state } = useApp();
    const sessionCount = state?.sessions.length ?? 0;
    const armedCount = state?.council.advisors.filter((advisor) => advisor.enabled).length ?? 0;

    const tabs = [
        { id: 'deck', label: '01 // FLEET & 3-STAGE FAILOVER DECK', count: sessionCount },
        { id: 'council', label: '⚖️ 02 // LLM COUNCIL WAR ROOM (GROUP CHAT)', count: `${armedCount} ARMED` },
    ];

    return (
        <nav className="mode-nav">
            {tabs.map((tab) => (
                <button key={tab.id} type="button" className={`mode-tab${surface === tab.id ? ' active' : ''}`} onClick={() => onChange(tab.id)}>
                    <span>{tab.label}</span>
                    <span className="mode-count">{tab.count}</span>
                </button>
            ))}
        </nav>
    );
}
