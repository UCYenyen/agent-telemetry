import { isRunning } from '../../lib/council';
import PanelHeader from '../common/PanelHeader';

function roomMeta(room) {
    const status = isRunning(room) ? `${room.status.replace(/_/g, ' ').toUpperCase()}` : room.status === 'interrupted' ? 'INTERRUPTED' : 'VERDICT READY';

    return `${status} · ${room.messages_count} entries`;
}

export default function RoomList({ rooms, activeId, onSelect, onNew, onDelete }) {
    return (
        <aside className="panel">
            <PanelHeader index="ROOMS" title="Deliberations">
                <button type="button" className="ghost btn-sm" onClick={onNew}>
                    + NEW
                </button>
            </PanelHeader>
            <ul className="room-list">
                {rooms.length === 0 && (
                    <li className="room-item">
                        <span className="room-item-meta">No deliberations yet. Ask a question to convene the council.</span>
                    </li>
                )}
                {rooms.map((room) => (
                    <li key={room.id} className={`room-item${room.id === activeId ? ' active' : ''}`}>
                        <button
                            type="button"
                            className="room-item-body"
                            onClick={() => onSelect(room.id)}
                            aria-current={room.id === activeId ? 'true' : undefined}
                        >
                            <span className="room-item-title">{room.title}</span>
                            <span className="room-item-meta">{roomMeta(room)}</span>
                        </button>
                        <button
                            type="button"
                            className="quiet btn-xs"
                            onClick={(event) => {
                                event.stopPropagation();
                                onDelete(room.id);
                            }}
                            title="Delete room"
                            aria-label={`Delete room ${room.title}`}
                        >
                            Delete
                        </button>
                    </li>
                ))}
            </ul>
        </aside>
    );
}
