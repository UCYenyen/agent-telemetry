import { useState } from 'react';
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
    const [surface, setSurface] = useState(sharedToken ? 'concord' : 'deck');
    const [deploying, setDeploying] = useState(false);
    const [consoleSessionId, setConsoleSessionId] = useState(null);

    const refresh = () => {
        toast('Syncing fleet telemetry…');
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
                {state && surface === 'deck' && <FleetDeck onOpenConsole={setConsoleSessionId} />}
                {(surface === 'concord' || sharedToken) && <ConcordSurface initialFullscreen={Boolean(sharedToken)} />}
                {state && surface === 'council' && <CouncilSurface />}
            </div>
            {state && <DeploySessionModal open={deploying} onClose={() => setDeploying(false)} />}
            {state && <SessionConsole sessionId={consoleSessionId} onSelectSession={setConsoleSessionId} onClose={() => setConsoleSessionId(null)} />}
        </>
    );
}
