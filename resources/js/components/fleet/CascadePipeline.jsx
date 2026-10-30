import { Fragment } from 'react';

const STEP_CLASSES = { claude: 's1', antigravity: 's2', openrouter: 's3' };

export default function CascadePipeline({ primary, fallbacks }) {
    return (
        <div className="cascade-pipeline">
            <div className="cascade-step s1">1. PRIMARY ({primary || 'CLAUDE'})</div>
            {fallbacks.map((fallback, index) => {
                const missingKey = fallback.provider === 'openrouter' && !fallback.has_key;

                return (
                    <Fragment key={fallback.id}>
                        <div className="cascade-arrow" aria-hidden="true">→</div>
                        <div
                            className={`cascade-step ${STEP_CLASSES[fallback.provider]}${missingKey ? ' inactive' : ''}`}
                            title={missingKey ? 'Skipped: no OpenRouter API key' : undefined}
                        >
                            {index + 2}. {fallback.model}{missingKey ? ' (no key, skipped)' : ''}
                        </div>
                    </Fragment>
                );
            })}
        </div>
    );
}
