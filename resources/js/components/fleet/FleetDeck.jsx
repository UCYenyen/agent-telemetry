import SessionList from './SessionList';
import FallbackPanel from './FallbackPanel';

export default function FleetDeck({ onOpenConsole }) {
    return (
        <div className="deck-grid">
            <SessionList onOpenConsole={onOpenConsole} />
            <FallbackPanel />
        </div>
    );
}
