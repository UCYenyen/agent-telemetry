import { Fragment } from 'react';

const STEP_CLASSES = { claude: 's1', antigravity: 's2', openrouter: 's3' };

export default function CascadePipeline({ primary, fallbacks }) {
    return (
        <div className="cascade-pipeline">
            <div className="cascade-step s1">1. PRIMARY ({primary || 'CLAUDE'})</div>
            {fallbacks.map((fallback, index) => (
                <Fragment key={fallback.id}>
                    <div className="cascade-arrow">→</div>
                    <div className={`cascade-step ${STEP_CLASSES[fallback.provider]}`}>
                        {index + 2}. {fallback.model}
                    </div>
                </Fragment>
            ))}
        </div>
    );
}
