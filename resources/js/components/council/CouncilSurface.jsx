import { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import AdvisorRoster from './AdvisorRoster';
import RoomList from './RoomList';
import WarRoom from './WarRoom';

export default function CouncilSurface() {
    const { state, run, toast } = useApp();
    const [selectedId, setSelectedId] = useState(undefined);
    const rooms = state.council.rooms;
    const activeId = selectedId === undefined ? rooms[0]?.id ?? null : selectedId;

    const ask = async (question) => {
        toast('Convening LLM Council advisors in parallel…');
        const room = await run(() => api.post('/api/council/rooms', { question, room_id: activeId }));

        if (room) {
            setSelectedId(room.id);
        }
    };

    const remove = (roomId) => {
        if (window.confirm('Delete this Council deliberation room?')) {
            if (roomId === activeId) {
                setSelectedId(undefined);
            }
            run(() => api.delete(`/api/council/rooms/${roomId}`));
        }
    };

    return (
        <div>
            <AdvisorRoster />
            <div className="council-chamber">
                <RoomList rooms={rooms} activeId={activeId} onSelect={setSelectedId} onNew={() => setSelectedId(null)} onDelete={remove} />
                <WarRoom summary={rooms.find((room) => room.id === activeId)} onAsk={ask} />
            </div>
        </div>
    );
}
