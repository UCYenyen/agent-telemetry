import { useApp } from '../../context/AppContext';

export default function TelemetryBar() {
    const { state, syncedAt } = useApp();
    const openRouter = state?.openrouter;
    const isAdmin = Boolean(state?.user?.role === 'admin' || state?.user?.is_admin);

    return (
        <div className="telemetry-bar">
            <div className="telemetry-left">
                <span className="sys-pill">
                    <span className="pulse-dot" /> TELEMETRY ONLINE
                </span>
                <span>ROLE: {(state?.user?.role || 'no_role').toUpperCase()}</span>
                {isAdmin && <span>VPS SESSIONS: {state?.sessions?.length ?? 0}</span>}
                {isAdmin && !openRouter && <span className="badge openrouter">OPENROUTER: CHECKING...</span>}
                {isAdmin && openRouter?.has_key && <span className="badge fb-on">OPENROUTER: ARMED ({openRouter.masked_key})</span>}
                {isAdmin && openRouter && !openRouter.has_key && <span className="badge openrouter">OPENROUTER: NO KEY</span>}
            </div>
            <div className="telemetry-right">
                <span>
                    {syncedAt === null && 'SYNCING...'}
                    {syncedAt === false && 'TELEMETRY OFFLINE'}
                    {syncedAt && `LAST SYNC // ${syncedAt.toLocaleTimeString()}`}
                </span>
            </div>
        </div>
    );
}
