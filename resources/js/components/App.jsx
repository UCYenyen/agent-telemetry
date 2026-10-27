import { useState } from 'react';
import { useApp } from '../context/AppContext';
import TelemetryBar from './layout/TelemetryBar';
import Masthead from './layout/Masthead';
import ModeNav from './layout/ModeNav';
import FleetDeck from './fleet/FleetDeck';
import DeploySessionModal from './fleet/DeploySessionModal';
import SessionConsole from './console/SessionConsole';
import CouncilSurface from './council/CouncilSurface';

export default function App() {
    const { state, reload, toast } = useApp();
    const [surface, setSurface] = useState('deck');
    const [deploying, setDeploying] = useState(false);
    const [consoleSessionId, setConsoleSessionId] = useState(null);

    const refresh = () => {
        toast('Syncing fleet telemetry…');
        reload();
    };

    return (
        <>
            <TelemetryBar />
            <div className="shell">
                <Masthead onRefresh={refresh} onDeploy={() => setDeploying(true)} />
                <ModeNav surface={surface} onChange={setSurface} />
                {state && surface === 'deck' && <FleetDeck onOpenConsole={setConsoleSessionId} />}
                {state && surface === 'council' && <CouncilSurface />}
            </div>
            {state && <DeploySessionModal open={deploying} onClose={() => setDeploying(false)} />}
            {state && <SessionConsole sessionId={consoleSessionId} onClose={() => setConsoleSessionId(null)} />}
        </>
    );
}
