import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import ModelSelect from '../common/ModelSelect';

const ENGINES = [
    ['auto', 'AUTO (Primary → Fallback Chain)'],
    ['claude', 'CLAUDE CODE'],
    ['antigravity', 'GOOGLE ANTIGRAVITY'],
    ['openrouter', 'OPENROUTER API DIRECT'],
];

export default function DefaultsForm({ apiKey, onSaved }) {
    const { state, run } = useApp();
    const [form, setForm] = useState(() => ({
        claude_model: state.defaults.claude_model,
        agent: state.defaults.agent,
        workdir: state.defaults.workdir,
        fallback_enabled: Boolean(state.defaults.fallback_enabled),
    }));

    const set = (key) => (value) => setForm((current) => ({ ...current, [key]: value }));

    const save = async (event) => {
        event.preventDefault();
        const payload = { ...form, workdir: form.workdir.trim(), ...(apiKey.trim() && { openrouter_api_key: apiKey.trim() }) };
        const saved = await run(() => api.patch('/api/settings', payload), 'Default workspace & failover settings saved!');

        if (saved) {
            onSaved();
        }
    };

    return (
        <form onSubmit={save}>
            <div className="grid2">
                <div className="field">
                    <label htmlFor="default-model">Primary Model (Tier 0)</label>
                    <ModelSelect id="default-model" models={state.models.claude} value={form.claude_model} onChange={set('claude_model')} />
                </div>
                <div className="field">
                    <label htmlFor="default-agent">Default New Session Engine</label>
                    <select id="default-agent" value={form.agent} onChange={(event) => set('agent')(event.target.value)}>
                        {ENGINES.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            <div className="field">
                <label htmlFor="default-workdir">Root Workspace Path</label>
                <input type="text" id="default-workdir" value={form.workdir} onChange={(event) => set('workdir')(event.target.value)} />
            </div>

            <label className="fallback-box">
                <input type="checkbox" checked={form.fallback_enabled} onChange={(event) => set('fallback_enabled')(event.target.checked)} />
                <div className="fallback-box-text">
                    <strong>🛡️ ENABLE MULTI-STAGE CASCADING FAILOVER</strong>
                    Automatically steps through your Fallback Chain above (#1 → #2 → #3…) if the primary model hits rate/quota limits.
                </div>
            </label>

            <button type="submit" className="btn-block">
                SAVE DEFAULT WORKSPACE &amp; ENGINE SETTINGS
            </button>
        </form>
    );
}
