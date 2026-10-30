import { useCallback, useEffect, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { useInterval } from '../../hooks/useInterval';
import { api } from '../../lib/api';
import { isRunning } from '../../lib/council';
import PanelHeader from '../common/PanelHeader';
import ChatComposer from '../common/ChatComposer';
import CouncilStream from './CouncilStream';
import CouncilEmptyState from './CouncilEmptyState';

const IDLE_DETAIL = 'Convene all enabled advisors in parallel. Each personality thinks independently using its selected CLI or OpenRouter model.';

function StageBadge({ room }) {
    if (!room) {
        return <span className="badge claude">READY FOR QUESTION</span>;
    }

    if (room.status === 'interrupted') {
        return <span className="badge fb-off">INTERRUPTED</span>;
    }

    return <span className={`badge ${isRunning(room) ? 'fallback' : 'antigravity'}`}>{room.status.replace(/_/g, ' ').toUpperCase()}</span>;
}

export default function WarRoom({ summary, onAsk }) {
    const { state } = useApp();
    const [room, setRoom] = useState(null);
    const roomId = summary?.id;
    const activeRoom = room?.id === roomId ? room : null;
    const running = isRunning(activeRoom) || isRunning(summary);
    const armed = state.council.advisors.filter((advisor) => advisor.enabled).length;

    const fetchRoom = useCallback(async () => {
        if (!roomId) {
            return;
        }

        const fresh = await api.get(`/api/council/rooms/${roomId}`).catch(() => null);

        if (fresh) {
            setRoom(fresh);
        }
    }, [roomId]);

    useEffect(() => {
        fetchRoom();
    }, [fetchRoom, summary?.updated_at]);

    useInterval(fetchRoom, running ? 1500 : null);

    return (
        <section className="panel war-room-panel">
            <PanelHeader index="WAR ROOM" title={activeRoom?.title ?? 'New Council Deliberation'}>
                <StageBadge room={activeRoom} />
            </PanelHeader>

            <div className="war-room-status-banner">
                <span>{activeRoom?.status_detail || IDLE_DETAIL}</span>
            </div>

            <div className="war-room-stream">
                {activeRoom ? <CouncilStream room={activeRoom} running={isRunning(activeRoom)} onAsk={onAsk} /> : <CouncilEmptyState onAsk={onAsk} />}
            </div>

            <ChatComposer
                rows={2}
                placeholder="Present a high-stakes decision, architecture tradeoff, or strategy question to the Council… (Enter to convene, Shift+Enter for newline)"
                submitLabel="[COUNCIL] CONVENE COUNCIL ↵"
                onSubmit={onAsk}
                hint={
                    <>
                        <span>{armed} independent advisors armed</span>
                        <span>·</span>
                        <span>Cascade: Claude → Antigravity → OpenRouter</span>
                    </>
                }
            />
        </section>
    );
}
