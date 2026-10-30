import { useEffect, useRef, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { AGENTS } from '../../lib/agents';

const GROUPS = ['openrouter', 'claude', 'antigravity'];

export default function ModelPicker({ kind, current, onSelect }) {
    const { state } = useApp();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const container = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const close = (event) => {
            if (!container.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const toggle = () => {
        setQuery('');
        setOpen(!open);
    };

    const choose = (model) => {
        setOpen(false);
        onSelect(model);
    };

    const needle = query.trim().toLowerCase();

    return (
        <div ref={container}>
            <button type="button" className="ow-model-btn" onClick={toggle} aria-expanded={open}>
                <span className="ow-model-icon">{AGENTS[kind].icon}</span>
                <div>
                    <span className="ow-model-label-sub">ACTIVE MODEL // CLICK TO SWITCH</span>
                    <span>{current || 'default'}</span>
                </div>
                <span className="ow-model-caret">▾</span>
            </button>

            {open && (
                <div className="ow-dropdown">
                    <div className="ow-dropdown-search">
                        <input type="text" autoFocus placeholder="Filter Claude, Gemini, or OpenRouter models..." value={query} onChange={(event) => setQuery(event.target.value)} />
                    </div>
                    {GROUPS.map((group) => (
                        <div key={group}>
                            <div className="ow-dropdown-group">
                                {AGENTS[group].icon} {AGENTS[group].fleet}
                            </div>
                            {state.models[group]
                                .filter((model) => !needle || model.toLowerCase().includes(needle))
                                .map((model) => (
                                    <button key={model} type="button" className={`ow-dropdown-item${model === current ? ' active' : ''}`} onClick={() => choose(model)}>
                                        <span>
                                            {AGENTS[group].icon} {model}
                                        </span>
                                        {model === current && <span>OK</span>}
                                    </button>
                                ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
