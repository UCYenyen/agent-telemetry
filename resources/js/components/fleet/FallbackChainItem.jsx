import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import AgentBadge from '../common/AgentBadge';

function KeyBadge({ fallback }) {
    if (fallback.provider !== 'openrouter') {
        return <span className="spec-chip">CLI NATIVE</span>;
    }

    if (fallback.has_key) {
        return (
            <span className="badge fb-on" title="API Key configured">
                [KEY] {fallback.masked_key || 'KEY SET'}
            </span>
        );
    }

    return (
        <span className="badge openrouter" title="Paste API key in input box and re-add">
            [WARN] NO API KEY
        </span>
    );
}

export default function FallbackChainItem({ fallback, rank, isFirst, isLast }) {
    const { run } = useApp();
    const url = `/api/fallbacks/${fallback.id}`;
    const move = (direction) => run(() => api.patch(url, { direction }));

    return (
        <li className="fb-chain-item">
            <div className="fb-chain-left">
                <span className="fb-rank">#{rank}</span>
                <AgentBadge kind={fallback.provider} />
                <span className="fb-model-name" title={fallback.model}>
                    {fallback.model}
                </span>
                <KeyBadge fallback={fallback} />
            </div>
            <div className="fb-chain-actions">
                <button type="button" className="fb-mini-btn" onClick={() => move('up')} disabled={isFirst} title="Move Up" aria-label="Move up">
                    ↑
                </button>
                <button type="button" className="fb-mini-btn" onClick={() => move('down')} disabled={isLast} title="Move Down" aria-label="Move down">
                    ↓
                </button>
                <button
                    type="button"
                    className="fb-mini-btn danger"
                    onClick={() => run(() => api.delete(url), 'Fallback removed from chain')}
                    title="Remove Fallback"
                    aria-label="Remove fallback"
                >
                    X
                </button>
            </div>
        </li>
    );
}
