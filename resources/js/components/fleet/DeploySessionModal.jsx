import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { modelsForAgent } from '../../lib/agents';
import Modal from '../common/Modal';
import ModelSelect from '../common/ModelSelect';

const ENGINES = [
    ['auto', 'Auto (Claude → Antigravity → OpenRouter)'],
    ['claude', 'Anthropic Claude Code'],
    ['antigravity', 'Google Antigravity'],
    ['openrouter', 'OpenRouter API Direct'],
];

function DeployForm({ onClose, onDeployed }) {
    const { state, run, toast } = useApp();
    const [form, setForm] = useState({ title: '', agent: 'auto', model: '', workdir: state.defaults.workdir || '/root' });
    const [submitting, setSubmitting] = useState(false);
    const models = modelsForAgent(state.models, form.agent);
    const useOpenRouter = form.agent === 'openrouter';

    const set = (key) => (value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        toast('Deploying session…');
        const result = await run(
            () => api.post('/api/sessions', { ...form, title: form.title.trim(), workdir: form.workdir.trim(), model: form.model || models[0], use_openrouter: useOpenRouter }),
            'Session deployed',
        );
        setSubmitting(false);
        // Keep the form open on failure so nothing has to be retyped.
        if (result !== null) {
            onClose();
            if (result?.id) onDeployed?.(result);
        }
    };

    return (
        <form onSubmit={submit}>
            <div className="field">
                <label htmlFor="new-title">Session name</label>
                <input type="text" id="new-title" placeholder="e.g. CeDX MCP or Core Refactor" value={form.title} onChange={(event) => set('title')(event.target.value)} required />
            </div>
            <div className="field">
                <label className="check-label boxed">
                    <input type="checkbox" checked={useOpenRouter} onChange={(event) => setForm((current) => ({ ...current, agent: event.target.checked ? 'openrouter' : 'auto', model: '' }))} />
                    <span>USE OPENROUTER API FOR THIS AGENT (TOGGLE)</span>
                </label>
            </div>
            <div className="grid2">
                <div className="field">
                    <label htmlFor="new-agent">Engine Provider</label>
                    <select id="new-agent" value={form.agent} onChange={(event) => setForm((current) => ({ ...current, agent: event.target.value, model: '' }))}>
                        {ENGINES.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="field">
                    <label htmlFor="new-model">Target Model</label>
                    <ModelSelect id="new-model" models={models} value={form.model} onChange={set('model')} />
                </div>
            </div>
            <div className="field">
                <label htmlFor="new-workdir">Target Working Directory</label>
                <input type="text" id="new-workdir" value={form.workdir} onChange={(event) => set('workdir')(event.target.value)} />
            </div>
            <div className="foot">
                <button type="button" className="ghost" onClick={onClose}>
                    Cancel
                </button>
                <button type="submit" disabled={submitting}>{submitting ? 'Deploying…' : 'Deploy session'}</button>
            </div>
        </form>
    );
}

export default function DeploySessionModal({ open, onClose, onDeployed }) {
    return (
        <Modal open={open} onClose={onClose}>
            <div className="dlg-head">Deploy New Agent Session</div>
            <DeployForm onClose={onClose} onDeployed={onDeployed} />
        </Modal>
    );
}
