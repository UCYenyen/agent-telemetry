import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import PanelHeader from '../common/PanelHeader';
import CascadePipeline from './CascadePipeline';
import FallbackBuilder from './FallbackBuilder';
import DefaultsForm from './DefaultsForm';

export default function FallbackPanel() {
    const { state } = useApp();
    const [apiKey, setApiKey] = useState('');
    const { has_key: hasKey, masked_key: maskedKey } = state.openrouter;

    return (
        <aside className="panel">
            <PanelHeader index="02" title="Cascading Fallback Chain">
                <span className={`badge ${hasKey ? 'fb-on' : 'openrouter'}`}>{hasKey ? `[KEY] KEY ARMED (${maskedKey})` : '[WARN] PASTE API KEY'}</span>
            </PanelHeader>
            <div className="config-body">
                <CascadePipeline primary={state.defaults.claude_model} fallbacks={state.fallbacks} />
                <FallbackBuilder apiKey={apiKey} onApiKeyChange={setApiKey} />
                <DefaultsForm apiKey={apiKey} onSaved={() => setApiKey('')} />
            </div>
        </aside>
    );
}
