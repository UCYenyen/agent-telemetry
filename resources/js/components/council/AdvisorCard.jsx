import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { modelsFor } from '../../lib/agents';
import ModelSelect from '../common/ModelSelect';
import OpenRouterToggle from '../common/OpenRouterToggle';

export default function AdvisorCard({ advisor, onEdit }) {
    const { state, run, toast } = useApp();
    const url = `/api/advisors/${advisor.id}`;
    const color = advisor.color || '#ff5722';

    const update = (changes, message) => run(() => api.patch(url, changes), message);

    const toggleOpenRouter = (enabled) => {
        toast(`Switching advisor to ${enabled ? 'OpenRouter API' : 'Local CLI Fleet'}…`);
        update({ use_openrouter: enabled });
    };

    const changeModel = (model) => {
        toast(`Assigning ${model}…`);
        update({ model }, `Advisor model updated to ${model}`);
    };

    const remove = () => {
        if (window.confirm('Remove this advisor personality from the Council?')) {
            run(() => api.delete(url), 'Advisor removed');
        }
    };

    return (
        <div className={`advisor-card${advisor.enabled ? '' : ' disabled'}`} style={{ borderTop: `3px solid ${color}` }}>
            <div className="advisor-card-top">
                <div className="advisor-identity">
                    <div className="advisor-icon" style={{ borderColor: color }}>
                        {advisor.icon || '🧠'}
                    </div>
                    <div className="advisor-name" title={advisor.name}>
                        {advisor.name}
                    </div>
                </div>
                <button type="button" className={`badge ${advisor.enabled ? 'fb-on' : 'fb-off'}`} onClick={() => update({ enabled: !advisor.enabled })}>
                    {advisor.enabled ? 'ARMED' : 'OFF'}
                </button>
            </div>

            <div className="advisor-prompt-preview" title={advisor.prompt}>
                {advisor.prompt}
            </div>

            <div className="advisor-model-box">
                <div className="advisor-or-row">
                    <label>MODEL SOURCE</label>
                    <OpenRouterToggle on={advisor.use_openrouter} onToggle={toggleOpenRouter} title="Toggle OpenRouter API for this advisor" />
                </div>
                <ModelSelect models={modelsFor(state.models, advisor.use_openrouter)} value={advisor.model} onChange={changeModel} aria-label={`${advisor.name} model`} />
            </div>

            <div className="advisor-card-foot">
                <button type="button" className="quiet muted btn-xs" onClick={onEdit}>
                    ✎ EDIT LENS
                </button>
                <button type="button" className="quiet btn-xs" onClick={remove}>
                    ✕ REMOVE
                </button>
            </div>
        </div>
    );
}
