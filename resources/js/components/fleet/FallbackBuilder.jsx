import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import FallbackChainItem from './FallbackChainItem';

const PROVIDER_GROUPS = [
    { provider: 'openrouter', label: '🌐 OpenRouter API Models (Requires API Key)' },
    { provider: 'antigravity', label: '✨ Google Antigravity CLI Models' },
    { provider: 'claude', label: '⚡ Anthropic Claude CLI Models' },
];

export default function FallbackBuilder({ apiKey, onApiKeyChange }) {
    const { state, run, toast } = useApp();
    const [choice, setChoice] = useState(`openrouter::${state.models.openrouter[0] ?? ''}`);
    const { has_key: hasKey, masked_key: maskedKey } = state.openrouter;

    const add = async () => {
        const [provider, model] = choice.split('::');
        const item = await run(() => api.post('/api/fallbacks', { provider, model, api_key: apiKey.trim() }), (created) => `Added fallback: ${created.model}`);

        if (item) {
            onApiKeyChange('');
        }
    };

    const syncModels = () => {
        toast('Fetching live model catalog from OpenRouter API…');
        run(() => api.post('/api/openrouter/models'), (result) => `Synced ${result.models.length} OpenRouter models!`);
    };

    return (
        <div className="fb-builder-box">
            <div className="fb-builder-head">
                <label htmlFor="fallback-model">➕ ADD FALLBACK MODEL (SELECT MODEL + PASTE API KEY)</label>
                <button type="button" className="ghost btn-xs" onClick={syncModels}>
                    ↻ SYNC OPENROUTER MODELS
                </button>
            </div>

            <div className="fb-adder-row">
                <select id="fallback-model" value={choice} onChange={(event) => setChoice(event.target.value)}>
                    {PROVIDER_GROUPS.map((group) => (
                        <optgroup key={group.provider} label={group.label}>
                            {state.models[group.provider].map((model) => (
                                <option key={model} value={`${group.provider}::${model}`}>
                                    {group.provider.toUpperCase()} — {model}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
                <input
                    type="password"
                    placeholder="Paste API Key (sk-or-v1-...)"
                    aria-label="Fallback API key"
                    value={apiKey}
                    onChange={(event) => onApiKeyChange(event.target.value)}
                />
                <button type="button" className="fb-add-btn" onClick={add}>
                    + ADD FALLBACK
                </button>
            </div>

            <div className="fb-hint">
                {hasKey
                    ? `Active key stored (${maskedKey}). Select a model, paste a key (optional), and click "+ ADD FALLBACK".`
                    : "Select a fallback model, paste your sk-or-v1-... key, and click '+ ADD FALLBACK'."}
            </div>

            <ul className="fb-chain-list">
                {state.fallbacks.length === 0 && (
                    <li className="fb-chain-item empty">No fallbacks configured. Select a model above, paste your API key, and click "+ ADD FALLBACK".</li>
                )}
                {state.fallbacks.map((fallback, index) => (
                    <FallbackChainItem key={fallback.id} fallback={fallback} rank={index + 1} isFirst={index === 0} isLast={index === state.fallbacks.length - 1} />
                ))}
            </ul>
        </div>
    );
}
