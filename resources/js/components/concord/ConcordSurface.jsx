import { useCallback, useEffect, useState } from 'react';
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
    const isAdmin = Boolean(state?.user?.role === 'admin' || state?.user?.is_admin);

    const [selectedRoomId, setSelectedRoomId] = useState(null);
    const [roomDetail, setRoomDetail] = useState(null);
    const [fullscreen, setFullscreen] = useState(Boolean(initialFullscreen || sharedToken));
    const [shareModalOpen, setShareModalOpen] = useState(false);
    const [connectModalOpen, setConnectModalOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [newRoomTitle, setNewRoomTitle] = useState('');
    const [creatingRoom, setCreatingRoom] = useState(false);

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

    const handleSendPrompt = async (payload) => {
        if (!roomDetail) return;
        setSending(true);
        try {
            const token = sharedToken || roomDetail.share_token;
            const updated = await api.post(`/api/concord/shared/${token}/messages`, {
                ...payload,
                sender_email: payload.sender_email || visitorEmail || state?.user?.email || undefined,
            });
            setRoomDetail(updated);
            toast('Prompt synced to VPS Shared Memory & local computer queues.');
        } catch (err) {
            toast(err.message);
        } finally {
            setSending(false);
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
            toast(`Created group chat project "${created.title}"`);
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
    const accessBadge = roomDetail?.access_mode === 'invite_only'
        ? 'RESTRICTED (INVITE ONLY - CHECKS PROJECT ROLE)'
        : roomDetail?.access_mode === 'link_view'
            ? 'ANYONE WITH LINK CAN VIEW (CHECKS PROJECT ROLE FOR EDIT)'
            : 'ANYONE WITH LINK CAN EDIT (ROLE BYPASSED)';

    return (
        <section className={`concord-surface${fullscreen ? ' concord-fullscreen' : ''}`}>
            <header className="concord-topbar">
                <div className="concord-topbar-left">
                    <span className="cc-brand-badge">CONCORD // MULTI-AGENT GROUP CHAT</span>
                    <div className="concord-room-titles">
                        <h2 className="concord-room-title">{roomDetail?.title || 'Loading Shared Memory Hub...'}</h2>
                        <span className="concord-room-sub">
                            MODE: {accessBadge} // YOUR PROJECT ROLE: {projectRole.toUpperCase()} {bypassedByLink ? '(BYPASSED BY EDIT LINK)' : ''}
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
                                    {r.title} ({r.participants_count ?? 2} agents)
                                </option>
                            ))}
                        </select>
                    )}

                    {roomDetail && (
                        <>
                            {canEdit && (
                                <button
                                    type="button"
                                    className="btn btn-emerald"
                                    onClick={() => setConnectModalOpen(true)}
                                >
                                    Connect Local PC / MCP
                                </button>
                            )}
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => setShareModalOpen(true)}
                            >
                                {isAdmin ? 'Share & Manage Roles' : 'Roles & Share Info'}
                            </button>
                            <button
                                type="button"
                                className="btn"
                                onClick={() => copyText(
                                    roomDetail.share_url || `${window.location.origin}/?room=${roomDetail.share_token}`,
                                    'Copied group chat share link!'
                                )}
                            >
                                Copy Link
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

            {!sharedToken && !fullscreen && isAdmin && (
                <form className="concord-new-room-bar" onSubmit={handleCreateRoom}>
                    <input
                        type="text"
                        className="input"
                        placeholder="Admin Only: Create a new multi-agent group chat project first, then invite members or share edit link..."
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
                    canEdit={canEdit}
                    sending={sending}
                    onSendPrompt={handleSendPrompt}
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
                onUpdated={fetchRoom}
            />

            <ConnectDeviceModal
                open={connectModalOpen}
                room={roomDetail}
                activeToken={sharedToken || roomDetail?.share_token}
                onClose={() => setConnectModalOpen(false)}
                onConnected={() => {
                    fetchRoom();
                    setConnectModalOpen(false);
                }}
            />
        </section>
    );
}
