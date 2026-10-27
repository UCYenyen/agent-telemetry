import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { modelsFor } from '../../lib/agents';
import ModelSelect from '../common/ModelSelect';
import OpenRouterToggle from '../common/OpenRouterToggle';

export default function ChairmanControls() {
    const { state, run } = useApp();
    const council = state.council;
    const save = (changes, message = 'Council synthesis settings updated') => run(() => api.patch('/api/council/settings', changes), message);

    return (
        <div className="council-controls-strip">
            <div className="council-settings-inline">
                <label htmlFor="chairman-model">⚖️ CHAIRMAN MODEL:</label>
                <OpenRouterToggle
                    on={council.chairman_use_openrouter}
                    onToggle={(enabled) => save({ chairman_use_openrouter: enabled }, `Chairman OpenRouter mode: ${enabled ? 'ON' : 'OFF'}`)}
                />
                <ModelSelect
                    id="chairman-model"
                    models={modelsFor(state.models, council.chairman_use_openrouter)}
                    value={council.chairman_model}
                    onChange={(model) => save({ chairman_model: model })}
                />
            </div>
            <label className="inline-check">
                <input type="checkbox" checked={council.enable_peer_review} onChange={(event) => save({ enable_peer_review: event.target.checked })} />
                <span>STAGE 2 ANONYMOUS PEER REVIEW (RESPONSES A–E)</span>
            </label>
        </div>
    );
}
