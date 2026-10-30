import { useCallback, useEffect, useMemo, useState } from 'react';
import { useApp } from '../../context/AppContext';
import { api } from '../../lib/api';
import { useInterval } from '../../hooks/useInterval';
import ConcordGroupChat from './ConcordGroupChat';
import SharedMemoryPanel from './SharedMemoryPanel';
import ShareRoomModal from './ShareRoomModal';
import ConnectDeviceModal from './ConnectDeviceModal';

export default function ConcordSurface({ initialFullscreen = false }) {
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
    const [newRoomTitle, setNewRoomTitle] = useState('');
    const [creatingRoom, setCreatingRoom] = useState(false);
    const [localParticipantId, setLocalParticipantId] = useState('');

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
        if (!selectedRoomId && rooms.length > 0) {
            setSelectedRoomId(rooms[0].id);
        }
    }, [rooms, selectedRoomId]);

    useEffect(() => {
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
            toast('Prompt synced to VPS Shared Memory & local computer queues.');
        } catch (err) {
            if (err.data?.requires_mcp) {
                setConnectModalOpen(true);
            }
            toast(err.message);
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
            toast(err.message);
        }
    };

    const handleCreateRoom = async (event) => {
        event.preventDefault();
        if (!newRoomTitle.trim() || !isAdmin) return;
        setCreatingRoom(true);
        try {
            const created = await api.post('/api/concord/rooms', {
                title: newRoomTitle.trim(),
                access_mode: 'invite_only',
            });
            setNewRoomTitle('');
            setSelectedRoomId(created.id);
            setRoomDetail(created);
            reload();
            toast(`Created group chat project "${created.title}" (0 agents connected - connect MCP to begin)`);
        } catch (err) {
            toast(err.message);
        } finally {
            setCreatingRoom(false);
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
                    <div className="concord-modal-kicker">[RESTRICTED] INVITE-ONLY MULTI-AGENT PROJECT</div>
                    <h2>{gateInfo?.title || 'Concord Shared Memory Room'}</h2>
                    <p>{gateInfo?.message || 'Enter the email address that was invited by Admin to join this project.'}</p>
                    <input
                        type="email"
                        className="input"
                        placeholder="your-invited-email@domain.com"
                        value={emailInput}
                        onChange={(e) => setEmailInput(e.target.value)}
                        required
                    />
                    <button type="submit" className="btn btn-emerald" style={{ marginTop: '0.75rem', width: '100%' }}>
                        Verify Invited Email & Join Group Chat
                    </button>
                </form>
            </div>
        );
    }

    const canEdit = roomDetail ? roomDetail.permission === 'edit' : isAdmin;
    const projectRole = roomDetail?.project_role || (isAdmin ? 'admin' : 'no_role');
    const bypassedByLink = Boolean(roomDetail?.role_bypassed_by_link);
    const totalParticipants = roomDetail?.participants?.length || 0;
    const totalMembers = (roomDetail?.invites?.length || 0) + 1;

    const accessBadge = roomDetail?.access_mode === 'invite_only'
        ? 'RESTRICTED (INVITE ONLY)'
        : roomDetail?.access_mode === 'link_view'
            ? 'ANYONE WITH LINK CAN VIEW'
            : 'ANYONE WITH LINK CAN EDIT (ROLE BYPASSED)';

    return (
        <section className={`concord-surface${fullscreen ? ' concord-fullscreen' : ''}`}>
            <header className="concord-topbar">
                <div className="concord-topbar-left">
                    <span className="cc-brand-badge">CONCORD // MULTI-AGENT GROUP CHAT</span>
                    <div className="concord-room-titles">
                        <h2 className="concord-room-title">{roomDetail?.title || 'Loading Shared Memory Hub...'}</h2>
                        <span className="concord-room-sub">
                            MODE: {accessBadge} // ROLE: {projectRole.toUpperCase()} {bypassedByLink ? '(BYPASSED BY EDIT LINK)' : ''} // {totalParticipants} MCP AGENT{totalParticipants === 1 ? '' : 'S'} CONNECTED
                        </span>
                    </div>
                </div>

                <div className="concord-topbar-actions">
                    {rooms.length > 1 && !sharedToken && (
                        <select
                            className="select concord-room-switcher"
                            value={selectedRoomId || ''}
                            onChange={(e) => setSelectedRoomId(e.target.value)}
                        >
                            {rooms.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.title} ({r.participants_count ?? 0} MCP agents)
                                </option>
                            ))}
                        </select>
                    )}

                    {roomDetail && (
                        <>
                            {canEdit && (
                                <button
                                    type="button"
                                    className={`btn ${hasConnectedMcp ? 'btn-emerald' : 'btn-amber-pulse'}`}
                                    onClick={() => setConnectModalOpen(true)}
                                >
                                    {hasConnectedMcp
                                        ? `MCP Connected (${myParticipants[0].device_name})`
                                        : '1. Connect Your MCP First'}
                                </button>
                            )}
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => setShareModalOpen(true)}
                            >
                                {isAdmin ? `Add / Manage Users (${totalMembers})` : `Team & Roles (${totalMembers})`}
                            </button>
                            <button
                                type="button"
                                className="btn"
                                onClick={() => copyText(
                                    roomDetail.share_url || `${window.location.origin}/?room=${roomDetail.share_token}`,
                                    'Copied group chat share link!'
                                )}
                            >
                                Copy Share Link
                            </button>
                        </>
                    )}

                    <button
                        type="button"
                        className="btn"
                        onClick={() => setFullscreen((prev) => !prev)}
                    >
                        {fullscreen ? 'Exit Fullscreen' : 'Fullscreen Prompt UI'}
                    </button>
                </div>
            </header>

            {/* 4-Step Live Readiness & Topology Pipeline Bar */}
            {roomDetail && (
                <div className="concord-pipeline-bar">
                    <div className="concord-pipeline-step ready">
                        <span className="concord-step-num">01</span>
                        <div className="concord-step-body">
                            <span className="concord-step-kicker">PROJECT ROLE & ACCESS</span>
                            <strong className="concord-step-val">
                                {projectRole.toUpperCase()} {canEdit ? '[CAN EDIT]' : '[VIEW ONLY]'}
                            </strong>
                        </div>
                    </div>

                    <div className="concord-pipeline-step ready" onClick={() => setShareModalOpen(true)} style={{ cursor: 'pointer' }}>
                        <span className="concord-step-num">02</span>
                        <div className="concord-step-body">
                            <span className="concord-step-kicker">UNLIMITED USERS ROSTER</span>
                            <strong className="concord-step-val">
                                {totalMembers} User{totalMembers === 1 ? '' : 's'} ({isAdmin ? '+ Click to Add More' : 'View Team'})
                            </strong>
                        </div>
                    </div>

                    <div
                        className={`concord-pipeline-step ${hasConnectedMcp ? 'ready' : 'locked'}`}
                        onClick={() => canEdit && setConnectModalOpen(true)}
                        style={{ cursor: canEdit ? 'pointer' : 'default' }}
                    >
                        <span className="concord-step-num">03</span>
                        <div className="concord-step-body">
                            <span className="concord-step-kicker">YOUR MCP GATEWAY</span>
                            <strong className="concord-step-val">
                                {hasConnectedMcp
                                    ? `CONNECTED // ${myParticipants[0].device_name}`
                                    : canEdit
                                      ? 'NOT CONNECTED (CLICK TO CONNECT MCP)'
                                      : 'READ-ONLY VIEWER'}
                            </strong>
                        </div>
                    </div>

                    <div className={`concord-pipeline-step ${totalParticipants > 0 && hasConnectedMcp ? 'ready' : 'locked'}`}>
                        <span className="concord-step-num">04</span>
                        <div className="concord-step-body">
                            <span className="concord-step-kicker">GROUP PROMPTING STATUS</span>
                            <strong className="concord-step-val">
                                {!canEdit
                                    ? 'LOCKED (NO_ROLE VIEW ONLY)'
                                    : hasConnectedMcp
                                      ? `UNLOCKED (${totalParticipants} ACTIVE AGENT${totalParticipants === 1 ? '' : 'S'})`
                                      : 'LOCKED UNTIL MCP CONNECTED'}
                            </strong>
                        </div>
                    </div>
                </div>
            )}

            {!sharedToken && !fullscreen && isAdmin && (
                <form className="concord-new-room-bar" onSubmit={handleCreateRoom}>
                    <input
                        type="text"
                        className="input"
                        placeholder="Admin Only: Create a new multi-agent group chat project (starts with 0 default agents)..."
                        value={newRoomTitle}
                        onChange={(e) => setNewRoomTitle(e.target.value)}
                    />
                    <button type="submit" className="btn btn-primary" disabled={creatingRoom}>
                        + New Group Chat Project (Admin)
                    </button>
                </form>
            )}

            <div className="concord-workspace-grid">
                <ConcordGroupChat
                    room={roomDetail}
                    currentUser={currentUser}
                    myParticipants={myParticipants}
                    hasConnectedMcp={hasConnectedMcp}
                    canEdit={canEdit}
                    isAdmin={isAdmin}
                    sending={sending}
                    onSendPrompt={handleSendPrompt}
                    onDisconnectParticipant={handleDisconnectParticipant}
                    onOpenConnectModal={() => setConnectModalOpen(true)}
                    onOpenRolesModal={() => setShareModalOpen(true)}
                />

                <SharedMemoryPanel
                    room={roomDetail}
                    activeToken={sharedToken || roomDetail?.share_token}
                    canEdit={canEdit}
                    onUpdated={fetchRoom}
                />
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
                activeToken={sharedToken || roomDetail?.share_token}
                onClose={() => setConnectModalOpen(false)}
                onDisconnectParticipant={handleDisconnectParticipant}
                onConnected={(participant) => {
                    if (participant?.id && roomDetail?.id) {
                        try {
                            localStorage.setItem(`concord_mcp_participant_${roomDetail.id}`, String(participant.id));
                        } catch {
                            // ignore
                        }
                        setLocalParticipantId(String(participant.id));
                    }
                    fetchRoom();
                    reload();
                }}
            />
        </section>
    );
}

