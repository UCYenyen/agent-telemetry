import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { modelsFor } from '../../lib/agents';
import Modal from '../common/Modal';
import ModelSelect from '../common/ModelSelect';

function AdvisorForm({ advisor, onClose }) {
    const { state, run } = useApp();
    const [form, setForm] = useState({
        name: advisor.name ?? '',
        model: advisor.model ?? 'gemini-3.8-flash-high',
        use_openrouter: Boolean(advisor.use_openrouter),
        icon: advisor.icon ?? '[MEM]',
        color: advisor.color ?? '#a855f7',
        prompt: advisor.prompt ?? '',
    });
    const models = modelsFor(state.models, form.use_openrouter);

    const set = (key) => (value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        onClose();
        const payload = { ...form, name: form.name.trim(), prompt: form.prompt.trim(), icon: form.icon.trim() || '[MEM]', model: models.includes(form.model) ? form.model : models[0] };
        run(() => (advisor.id ? api.patch(`/api/advisors/${advisor.id}`, payload) : api.post('/api/advisors', payload)), 'Personality saved to Council roster!');
    };

    return (
        <form onSubmit={submit}>
            <div className="field">
                <label className="check-label">
                    <input type="checkbox" checked={form.use_openrouter} onChange={(event) => set('use_openrouter')(event.target.checked)} />
                    <span>[OR] USE OPENROUTER API FOR THIS AGENT PERSONALITY</span>
                </label>
            </div>
            <div className="grid2">
                <div className="field">
                    <label htmlFor="advisor-name">Personality Name</label>
                    <input type="text" id="advisor-name" placeholder="e.g. The Security Paranoid" value={form.name} onChange={(event) => set('name')(event.target.value)} required />
                </div>
                <div className="field">
                    <label htmlFor="advisor-model">Assigned Model</label>
                    <ModelSelect id="advisor-model" models={models} value={form.model} onChange={set('model')} />
                </div>
            </div>
            <div className="grid2">
                <div className="field">
                    <label htmlFor="advisor-icon">Avatar Glyph / Emoji</label>
                    <input type="text" id="advisor-icon" maxLength={4} value={form.icon} onChange={(event) => set('icon')(event.target.value)} />
                </div>
                <div className="field">
                    <label htmlFor="advisor-color">Telemetry Accent Color</label>
                    <input type="color" id="advisor-color" value={form.color} onChange={(event) => set('color')(event.target.value)} />
                </div>
            </div>
            <div className="field">
                <label htmlFor="advisor-prompt">Thinking Lens / System Instructions</label>
                <textarea
                    id="advisor-prompt"
                    rows={4}
                    placeholder="Describe how this advisor thinks, what it prioritizes, and what blind spots it hunts for..."
                    value={form.prompt}
                    onChange={(event) => set('prompt')(event.target.value)}
                    required
                />
            </div>
            <div className="foot">
                <button type="button" className="ghost" onClick={onClose}>
                    CANCEL
                </button>
                <button type="submit">SAVE PERSONALITY</button>
            </div>
        </form>
    );
}

export default function AdvisorModal({ advisor, onClose }) {
    return (
        <Modal open={Boolean(advisor)} onClose={onClose}>
            {advisor && (
                <>
                    <div className="dlg-head">{advisor.id ? `Edit Personality: ${advisor.name}` : 'Add Custom Agent Personality'}</div>
                    <AdvisorForm advisor={advisor} onClose={onClose} />
                </>
            )}
        </Modal>
    );
}
