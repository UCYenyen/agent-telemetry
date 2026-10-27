import { AGENTS } from '../../lib/agents';

export default function AgentBadge({ kind, label, plain = false }) {
    const agent = AGENTS[kind];

    return <span className={`badge ${kind}`}>{plain ? agent.label : `${agent.icon} ${label ?? agent.label}`}</span>;
}
