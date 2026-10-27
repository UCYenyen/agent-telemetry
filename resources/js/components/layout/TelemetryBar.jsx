import { useApp } from '../../context/AppContext';

export default function TelemetryBar() {
    const { state, syncedAt } = useApp();
    const openRouter = state?.openrouter;

    return (
        <div className="telemetry-bar">
            <div className="telemetry-left">
                <span className="sys-pill">
                    <span className="pulse-dot" /> TELEMETRY ONLINE
                </span>
                <span>SESSIONS: {state?.sessions.length ?? 0}</span>
                {!openRouter && <span className="badge openrouter">🌐 OPENROUTER: CHECKING…</span>}
                {openRouter?.has_key && <span className="badge fb-on">🌐 OPENROUTER: ARMED ({openRouter.masked_key})</span>}
                {openRouter && !openRouter.has_key && <span className="badge openrouter">🌐 OPENROUTER: NO KEY</span>}
            </div>
            <div className="telemetry-right">
                <span>
                    {syncedAt === null && 'SYNCING…'}
                    {syncedAt === false && 'TELEMETRY OFFLINE'}
                    {syncedAt && `LAST SYNC // ${syncedAt.toLocaleTimeString()}`}
                </span>
            </div>
        </div>
    );
}
