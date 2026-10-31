import { useEffect, useState } from 'react';
import { useApp } from '../context/AppContext';
import TelemetryBar from './layout/TelemetryBar';
import Masthead from './layout/Masthead';
import ModeNav from './layout/ModeNav';
import FleetDeck from './fleet/FleetDeck';
import DeploySessionModal from './fleet/DeploySessionModal';
import SessionConsole from './console/SessionConsole';
import CouncilSurface from './council/CouncilSurface';
import ConcordSurface from './concord/ConcordSurface';

export default function App() {
    const { state, reload, toast, sharedToken } = useApp();
    const isAdmin = Boolean(state?.user?.role === 'admin' || state?.user?.is_admin);
    const [surface, setSurface] = useState(sharedToken ? 'concord' : 'deck');
    const [deploying, setDeploying] = useState(false);
    const [consoleSessionId, setConsoleSessionId] = useState(null);

    useEffect(() => {
        if (state && !isAdmin && surface !== 'concord') {
            setSurface('concord');
        }
    }, [state, isAdmin, surface]);

    const refresh = () => {
        toast('Syncing fleet telemetry...');
        reload();
    };

    if (sharedToken && !state) {
        return <ConcordSurface initialFullscreen />;
    }

    return (
        <>
            <TelemetryBar />
            <div className="shell">
                <Masthead onRefresh={refresh} onDeploy={() => setDeploying(true)} />
                <ModeNav surface={surface} onChange={setSurface} />
                {state && isAdmin && surface === 'deck' && <FleetDeck onOpenConsole={setConsoleSessionId} />}
                {(surface === 'concord' || sharedToken || (state && !isAdmin)) && (
                    <ConcordSurface initialFullscreen={Boolean(sharedToken)} onOpenConsole={setConsoleSessionId} onDeploy={() => setDeploying(true)} />
                )}
                {state && isAdmin && surface === 'council' && <CouncilSurface />}
            </div>
            {state && isAdmin && (
                <DeploySessionModal
                    open={deploying}
                    onClose={() => setDeploying(false)}
                    // Open the new session straight away; it is also listed under Fleet and in every Concord room.
                    onDeployed={(session) => setConsoleSessionId(session.id)}
                />
            )}
            {state && isAdmin && (
                <SessionConsole
                    sessionId={consoleSessionId}
                    onSelectSession={setConsoleSessionId}
                    onClose={() => setConsoleSessionId(null)}
                />
            )}
        </>
    );
}
