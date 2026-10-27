import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import PanelHeader from '../common/PanelHeader';
import ChairmanControls from './ChairmanControls';
import AdvisorCard from './AdvisorCard';
import AdvisorModal from './AdvisorModal';

export default function AdvisorRoster() {
    const { state, run } = useApp();
    const [editing, setEditing] = useState(null);

    const reset = () => {
        if (window.confirm('Reset Council roster to the 5 default personalities?')) {
            run(() => api.post('/api/advisors/reset'), 'Restored 5 default Council personalities');
        }
    };

    return (
        <section className="panel council-roster-bar">
            <PanelHeader index="ROSTER" title="Council Advisors · Per-Agent Model & OpenRouter Toggles">
                <div className="header-actions">
                    <button type="button" className="ghost" onClick={reset}>
                        ↺ RESET 5 DEFAULTS
                    </button>
                    <button type="button" onClick={() => setEditing({})}>
                        + ADD AGENT PERSONALITY
                    </button>
                </div>
            </PanelHeader>

            <ChairmanControls />

            <div className="advisor-grid">
                {state.council.advisors.map((advisor) => (
                    <AdvisorCard key={advisor.id} advisor={advisor} onEdit={() => setEditing(advisor)} />
                ))}
            </div>

            <AdvisorModal advisor={editing} onClose={() => setEditing(null)} />
        </section>
    );
}
