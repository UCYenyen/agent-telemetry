import { useCallback, useEffect, useMemo, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { useInterval } from '../../hooks/useInterval';
import AgentBoard, { presenceOf } from './AgentBoard';
import RoomChat from './RoomChat';
import SharedMemoryPanel from './SharedMemoryPanel';
import ShareRoomModal from './ShareRoomModal';
import ConnectDeviceModal from './ConnectDeviceModal';

export default function ConcordSurface({ initialFullscreen = false, onOpenConsole, onDeploy }) {
    const { state, reload, toast, sharedToken } = useApp();
    const rooms = state?.concord?.rooms || [];
    const currentUser = state?.user || null;
    const isAdmin = Boolean(currentUser?.role === 'admin' || currentUser?.is_admin);

    const [selectedRoomId, setSelectedRoomId] = useState(null);
    const [roomDetail, setRoomDetail] = useState(null);
    const [fullscreen, setFullscreen] = useState(Boolean(initialFullscreen || sharedToken));
    const [shareModalOpen, setShareModalOpen] = useState(false);
    const [connectModalOpen, setConnectModalOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [deletingRoom, setDeletingRoom] = useState(false);
    const [sideTab, setSideTab] = useState('chat');
    const [localParticipantId, setLocalParticipantId] = useState('');
    const [selectedAgentId, setSelectedAgentId] = useState(null);

    const [requiresEmailGate, setRequiresEmailGate] = useState(false);
    const [gateInfo, setGateInfo] = useState(null);
    const [visitorEmail, setVisitorEmail] = useState(() => {
        try {
            return localStorage.getItem('concord_visitor_email') || '';
        } catch {
            return '';
        }
    });
    const [emailInput, setEmailInput] = useState(visitorEmail);

    useEffect(() => {
        // Fall back to the newest group when nothing is picked or the picked one was deleted.
        if (rooms.length > 0 && !rooms.some((r) => r.id === selectedRoomId)) {
            setSelectedRoomId(rooms[0].id);
        }
    }, [rooms, selectedRoomId]);

    useEffect(() => {
        setSelectedAgentId(null);
        if (!roomDetail?.id) return;
        try {
            const saved = localStorage.getItem(`concord_mcp_participant_${roomDetail.id}`) || '';
            setLocalParticipantId(saved);
        } catch {
            setLocalParticipantId('');
        }
    }, [roomDetail?.id]);

    const fetchRoom = useCallback(async () => {
        try {
            if (sharedToken) {
                const emailParam = visitorEmail ? `?email=${encodeURIComponent(visitorEmail)}` : '';
                const data = await api.get(`/api/concord/shared/${sharedToken}${emailParam}`);
                setRoomDetail(data);
                setRequiresEmailGate(false);
                return;
            }

            const targetId = selectedRoomId || rooms[0]?.id;
            if (!targetId) return;
            const data = await api.get(`/api/concord/rooms/${targetId}`);
            setRoomDetail(data);
            setRequiresEmailGate(false);
        } catch (err) {
            if (err.status === 403 && err.data?.requires_email) {
                setRequiresEmailGate(true);
                setGateInfo(err.data);
            }
        }
    }, [sharedToken, visitorEmail, selectedRoomId, rooms]);

    useEffect(() => {
        fetchRoom();
    }, [fetchRoom]);

    useInterval(fetchRoom, 2500);

    const myParticipants = useMemo(() => {
        const list = roomDetail?.participants || [];
        if (list.length === 0) return [];

        const userEmail = String(currentUser?.email || visitorEmail || roomDetail?.viewer_email || '').trim().toLowerCase();
        const userName = String(currentUser?.name || currentUser?.username || '').trim().toLowerCase();

        return list.filter((p) => {
            if (localParticipantId && String(p.id) === String(localParticipantId)) return true;
            const pEmail = String(p.account_email || '').trim().toLowerCase();
            const pName = String(p.user_name || '').trim().toLowerCase();
            if (userEmail && pEmail && pEmail === userEmail) return true;
            if (userName && pName && pName === userName) return true;
            return false;
        });
    }, [roomDetail?.participants, roomDetail?.viewer_email, currentUser, visitorEmail, localParticipantId]);

    const hasConnectedMcp = myParticipants.length > 0;

    const handleSendPrompt = async (payload) => {
        if (!roomDetail) return;
        if (!hasConnectedMcp) {
            toast('Connect your local MCP agent first before prompting in the Group Chat.');
            setConnectModalOpen(true);
            return;
        }
        setSending(true);
        try {
            const token = sharedToken || roomDetail.share_token;
            const updated = await api.post(`/api/concord/shared/${token}/messages`, {
                ...payload,
                sender_email: payload.sender_email || visitorEmail || currentUser?.email || undefined,
            });
            setRoomDetail(updated);
            toast('Sent');
        } catch (err) {
            if (err.data?.requires_mcp) {
                setConnectModalOpen(true);
            }
            toast(err.message, 'error');
        } finally {
            setSending(false);
        }
    };

    const handleDisconnectParticipant = async (participant) => {
        if (!roomDetail || !participant) return;
        try {
            const token = sharedToken || roomDetail.share_token;
            await api.delete(`/api/concord/shared/${token}/participants/${participant.id}`);
            if (String(localParticipantId) === String(participant.id)) {
                try {
                    localStorage.removeItem(`concord_mcp_participant_${roomDetail.id}`);
                } catch {
                    // ignore
                }
                setLocalParticipantId('');
            }
            toast(`Disconnected MCP agent ${participant.name} (${participant.device_name})`);
            fetchRoom();
            reload();
        } catch (err) {
            toast(err.message, 'error');
        }
    };

    const handleDeleteRoom = async () => {
        if (!roomDetail || !isAdmin) return;
        const confirmed = window.confirm(
            roomDetail.agent_session_id
                ? `Delete "${roomDetail.title}"?\n\nThis stops its VPS session and permanently removes the group chat with its messages, shared memory, invites and connected agents.`
                : `Delete group chat "${roomDetail.title}"?\n\nThis permanently removes its messages, shared memory, invites and connected MCP agents.`
        );
        if (!confirmed) return;
        setDeletingRoom(true);
        try {
            await api.delete(`/api/concord/rooms/${roomDetail.id}`);
            try {
                localStorage.removeItem(`concord_mcp_participant_${roomDetail.id}`);
            } catch {
                // ignore
            }
            const nextRoom = rooms.find((r) => r.id !== roomDetail.id);
            setSelectedRoomId(nextRoom?.id || null);
            setRoomDetail(null);
            reload();
            toast(`Deleted group chat "${roomDetail.title}"`);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setDeletingRoom(false);
        }
    };

    const handleEmailGateSubmit = (event) => {
        event.preventDefault();
        if (!emailInput.trim()) return;
        const normalized = emailInput.trim().toLowerCase();
        try {
            localStorage.setItem('concord_visitor_email', normalized);
        } catch {
            // ignore storage errors
        }
        setVisitorEmail(normalized);
    };

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    if (requiresEmailGate) {
        return (
            <div className="concord-gate-screen">
                <form className="concord-gate-card" onSubmit={handleEmailGateSubmit}>
                    <h2>{gateInfo?.title || 'Concord Shared Memory Room'}</h2>
                    <p>{gateInfo?.message || 'Enter the email address that was invited by Admin to join this project.'}</p>
                    <label htmlFor="concord-gate-email">Invited email</label>
                    <input
                        id="concord-gate-email"
                        type="email"
                        className="input"
                        autoComplete="email"
                        placeholder="you@example.com"
                        value={emailInput}
                        onChange={(e) => setEmailInput(e.target.value)}
                        required
                    />
                    <button type="submit" className="btn btn-emerald" style={{ marginTop: '0.75rem', width: '100%' }}>
                        Join group chat
                    </button>
                </form>
            </div>
        );
    }

    if (!sharedToken && state && rooms.length === 0) {
        return (
            <section className="concord-surface room-view">
                <div className="agent-board-empty">
                    <h3>No group chats yet</h3>
                    <p>Every session you deploy gets its own group chat, with its own MCP link, email invites and roles.</p>
                    {isAdmin && onDeploy && (
                        <button type="button" className="btn btn-primary" onClick={onDeploy}>
                            Deploy a session
                        </button>
                    )}
                </div>
            </section>
        );
    }

    const canEdit = roomDetail ? roomDetail.permission === 'edit' : isAdmin;
    const participants = roomDetail?.participants || [];
    const myParticipantIds = new Set(myParticipants.map((p) => String(p.id)));
    const workingCount = participants.filter((p) => presenceOf(p, Date.now()) === 'working').length;
    const totalMembers = (roomDetail?.invites?.length || 0) + 1;
    const accessLabel = { invite_only: 'Invite only', link_view: 'Anyone with the link can view', link_edit: 'Anyone with the link can edit' }[roomDetail?.access_mode] || '';

    return (
        <section className={`concord-surface room-view${fullscreen ? ' concord-fullscreen' : ''}`}>
            <header className="room-head">
                <div className="room-head-main">
                    {rooms.length > 0 && !sharedToken ? (
                        <select
                            className="select room-switcher"
                            aria-label="Room"
                            value={selectedRoomId || ''}
                            onChange={(e) => setSelectedRoomId(e.target.value)}
                        >
                            {rooms.map((r) => (
                                <option key={r.id} value={r.id}>{r.title}</option>
                            ))}
                        </select>
                    ) : (
                        <h2 className="room-title">{roomDetail?.title || 'Loading room…'}</h2>
                    )}
                    <p className="room-meta">
                        {participants.length} agent{participants.length === 1 ? '' : 's'}
                        {workingCount > 0 && <span className="room-meta-working"> · {workingCount} working</span>}
                        {accessLabel && ` · ${accessLabel}`}
                        {roomDetail && !canEdit && ' · View only: ask the group admin for the team_member role to connect an agent'}
                    </p>
                </div>

                {roomDetail && (
                    <div className="room-actions">
                        {canEdit && (
                            <button type="button" className="btn btn-primary" onClick={() => setConnectModalOpen(true)}>
                                Connect an agent
                            </button>
                        )}
                        <button type="button" className="btn" onClick={() => setShareModalOpen(true)}>
                            Members ({totalMembers})
                        </button>
                        <details className="room-more">
                            <summary className="btn">More</summary>
                            <div className="room-more-menu">
                                <button
                                    type="button"
                                    className="btn"
                                    onClick={() => copyText(roomDetail.share_url || `${window.location.origin}/?room=${roomDetail.share_token}`, 'Copied room link')}
                                >
                                    Copy room link
                                </button>
                                <button type="button" className="btn" onClick={() => setFullscreen((prev) => !prev)}>
                                    {fullscreen ? 'Exit full screen' : 'Full screen'}
                                </button>
                                {isAdmin && !sharedToken && (
                                    <button type="button" className="btn btn-danger" onClick={handleDeleteRoom} disabled={deletingRoom}>
                                        {deletingRoom ? 'Deleting…' : roomDetail.agent_session_id ? 'Delete session and group' : 'Delete group'}
                                    </button>
                                )}
                            </div>
                        </details>
                    </div>
                )}
            </header>

            <div className="room-body">
                <main className="room-agents" aria-label="Agents">
                    <AgentBoard
                        participants={participants}
                        tasks={roomDetail?.local_tasks || []}
                        selectedId={selectedAgentId}
                        onSelect={(id) => {
                            setSelectedAgentId(id);
                            setSideTab('chat');
                        }}
                        myParticipantIds={myParticipantIds}
                        isAdmin={isAdmin}
                        canEdit={canEdit}
                        onDisconnect={handleDisconnectParticipant}
                        onConnect={() => setConnectModalOpen(true)}
                        onOpenConsole={onOpenConsole}
                        onDeleteGroup={handleDeleteRoom}
                    />
                </main>

                <aside className="room-side">
                    <div className="room-tabs" role="tablist" aria-label="Room panels">
                        {[['chat', 'Chat'], ['memory', `Shared memory (${(roomDetail?.shared_memory || []).length})`]].map(([id, label]) => (
                            <button
                                key={id}
                                type="button"
                                role="tab"
                                aria-selected={sideTab === id}
                                className={`room-tab${sideTab === id ? ' active' : ''}`}
                                onClick={() => setSideTab(id)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {sideTab === 'chat' ? (
                        <RoomChat
                            room={roomDetail}
                            currentUser={currentUser}
                            target={selectedAgentId || 'all'}
                            onTargetChange={(value) => setSelectedAgentId(value === 'all' ? null : value)}
                            myParticipants={myParticipants}
                            hasConnectedMcp={hasConnectedMcp}
                            canEdit={canEdit}
                            sending={sending}
                            onSendPrompt={handleSendPrompt}
                            onConnect={() => setConnectModalOpen(true)}
                        />
                    ) : (
                        <SharedMemoryPanel room={roomDetail} canEdit={canEdit} onUpdated={fetchRoom} />
                    )}
                </aside>
            </div>

            <ShareRoomModal
                open={shareModalOpen}
                room={roomDetail}
                onClose={() => setShareModalOpen(false)}
                onUpdated={() => {
                    fetchRoom();
                    reload();
                }}
            />

            <ConnectDeviceModal
                open={connectModalOpen}
                room={roomDetail}
                currentUser={currentUser}
                visitorEmail={visitorEmail}
                myParticipants={myParticipants}
                onClose={() => setConnectModalOpen(false)}
            />
        </section>
    );
}

