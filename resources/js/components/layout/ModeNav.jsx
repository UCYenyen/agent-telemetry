import { useApp } from '../../context/AppContext';

export default function ModeNav({ surface, onChange }) {
    const { state } = useApp();
    const isAdmin = Boolean(state?.user?.role === 'admin' || state?.user?.is_admin);
    const sessionCount = state?.sessions?.length ?? 0;
    const armedCount = state?.council?.advisors?.filter((advisor) => advisor.enabled).length ?? 0;
    const concordRoomsCount = state?.concord?.rooms?.length ?? 1;

    const tabs = isAdmin
        ? [
              { id: 'deck', label: 'Fleet', count: sessionCount, hint: `${sessionCount} agent sessions` },
              { id: 'concord', label: 'Group chat', count: concordRoomsCount, hint: `${concordRoomsCount} rooms` },
              { id: 'council', label: 'Council', count: armedCount, hint: `${armedCount} advisors armed` },
          ]
        : [{ id: 'concord', label: 'Group chat', count: concordRoomsCount, hint: `${concordRoomsCount} rooms` }];

    return (
        <nav className="mode-nav" aria-label="Sections">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    className={`mode-tab${surface === tab.id ? ' active' : ''}`}
                    aria-current={surface === tab.id ? 'page' : undefined}
                    onClick={() => onChange(tab.id)}
                >
                    <span>{tab.label}</span>
                    <span className="mode-count" title={tab.hint} aria-label={tab.hint}>{tab.count}</span>
                </button>
            ))}
        </nav>
    );
}
