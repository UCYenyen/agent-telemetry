export default function FailoverBadge({ session, onToggle }) {
    if (session.fallback_triggered) {
        return <span className="badge fallback">⚡ FAILOVER ({session.fallback_stage === 2 ? 'STAGE 2: OPENROUTER' : 'STAGE 1: ANTIGRAVITY'})</span>;
    }

    if (session.fallback_enabled) {
        return (
            <button type="button" className="badge fb-on" onClick={onToggle} title="3-Tier Failover Armed (Claude -> Antigravity -> OpenRouter)">
                🛡️ 3-TIER FAILOVER: ON
            </button>
        );
    }

    return (
        <button type="button" className="badge fb-off" onClick={onToggle}>
            FAILOVER: OFF
        </button>
    );
}
