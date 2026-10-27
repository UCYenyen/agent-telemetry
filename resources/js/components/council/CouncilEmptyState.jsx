import EmptyState from '../common/EmptyState';

const PROMPTS = [
    {
        title: '01 // PRODUCT & PRICING STRATEGY',
        sub: '"$97 workshop vs $497 full platform launch?"',
        question: 'Should we launch a $97 live workshop first or build a comprehensive $497 self-paced platform?',
    },
    {
        title: '02 // ARCHITECTURE PRESSURE-TEST',
        sub: 'Modular monolith vs distributed microservices',
        question: 'We need to choose between a monolithic modular architecture vs microservices for our new AI agent backend. Pressure-test this decision.',
    },
    {
        title: '03 // RESOURCE ALLOCATION',
        sub: 'Hire human ops vs build autonomous automation',
        question: 'Should I hire a dedicated ops engineer or build an automated self-healing agent pipeline first?',
    },
    {
        title: '04 // BLIND SPOT AUDIT',
        sub: 'Stress-test multi-model agent fleet risks',
        question: 'What is the biggest strategic blind spot in running multi-model AI agents in production?',
    },
];

export default function CouncilEmptyState({ onAsk }) {
    return (
        <EmptyState
            glyph="⚖️"
            title="Convene the LLM Council"
            description="Your question is dispatched in parallel to 5+ independent AI advisors (each with its own thinking lens & Claude / Gemini / OpenRouter model), cross-examined via anonymous peer review, and synthesized into a final Chairman Verdict."
        >
            <div className="ow-chips">
                {PROMPTS.map((prompt) => (
                    <button key={prompt.title} type="button" className="ow-chip" onClick={() => onAsk(prompt.question)}>
                        <div className="ow-chip-title">{prompt.title}</div>
                        <div className="ow-chip-sub">{prompt.sub}</div>
                    </button>
                ))}
            </div>
        </EmptyState>
    );
}
