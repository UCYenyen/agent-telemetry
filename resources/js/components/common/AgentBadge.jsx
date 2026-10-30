import { AGENTS } from '../../lib/agents';

export default function AgentBadge({ kind, label, plain = false }) {
    const agent = AGENTS[kind];

    return <span className={`badge ${kind}`}>{plain ? agent.label : label ?? agent.label}</span>;
}
