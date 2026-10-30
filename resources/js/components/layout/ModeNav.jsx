import { useApp } from '../../context/AppContext';

export default function ModeNav({ surface, onChange }) {
    const { state } = useApp();
    const isAdmin = Boolean(state?.user?.role === 'admin' || state?.user?.is_admin);
    const sessionCount = state?.sessions?.length ?? 0;
    const armedCount = state?.council?.advisors?.filter((advisor) => advisor.enabled).length ?? 0;
    const concordRoomsCount = state?.concord?.rooms?.length ?? 1;

    const tabs = isAdmin
        ? [
              { id: 'deck', label: '01 // FLEET & 3-STAGE FAILOVER DECK (ADMIN ONLY)', count: sessionCount },
              { id: 'concord', label: '02 // CONCORD MULTI-AGENT GROUP CHAT (SHARED MEMORY + LOCAL PC)', count: `${concordRoomsCount} LIVE` },
              { id: 'council', label: '03 // LLM COUNCIL WAR ROOM (ADMIN ONLY)', count: `${armedCount} ARMED` },
          ]
        : [
              { id: 'concord', label: '01 // CONCORD MULTI-AGENT GROUP CHAT (SHARED MEMORY + LOCAL PC)', count: `${concordRoomsCount} LIVE` },
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
