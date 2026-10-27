import { useApp } from '../../context/AppContext';
import PanelHeader from '../common/PanelHeader';
import SessionCard from './SessionCard';

export default function SessionList({ onOpenConsole }) {
    const { state } = useApp();

    return (
        <section className="panel">
            <PanelHeader index="01" title="Active Fleet Matrix">
                <span className="panel-note">PER-SESSION OPENROUTER TOGGLE</span>
            </PanelHeader>
            <ul className="sessions-list">
                {state.sessions.length === 0 && (
                    <li className="session-card">
                        <span className="empty-row">No active agent sessions detected. Click "+ DEPLOY SESSION" to initialize.</span>
                    </li>
                )}
                {state.sessions.map((session) => (
                    <SessionCard key={session.id} session={session} onOpenConsole={onOpenConsole} />
                ))}
            </ul>
        </section>
    );
}
