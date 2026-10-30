import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

const ACCESS_MODES = [
    {
        id: 'invite_only',
        icon: '🔒',
        title: 'Restricted (Invite Only)',
        subtitle: 'Only people invited by email can access this group chat room',
    },
    {
        id: 'link_view',
        icon: '👀',
        title: 'Anyone with the link — Can view',
        subtitle: 'Anyone with the link can watch the live group chat, agent thinking, and shared memory',
    },
    {
        id: 'link_edit',
        icon: '✏️',
        title: 'Anyone with the link — Can edit',
        subtitle: 'Anyone with the link can connect their local computer agent, prompt, and collaborate',
    },
];

export default function ShareRoomModal({ open, room, onClose, onUpdated }) {
    const { toast } = useApp();
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('editor');
    const [saving, setSaving] = useState(false);

    if (!open || !room) {
        return null;
    }

    const shareUrl = room.share_url || `${window.location.origin}/?room=${room.share_token}`;

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    const handleAccessModeChange = async (nextMode) => {
        setSaving(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}`, {
                access_mode: nextMode,
            });
            toast(`Link access updated to ${nextMode.replace('_', ' ')}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSaving(false);
        }
    };

    const handleInviteEmail = async (event) => {
        event.preventDefault();
        if (!email.trim()) return;
        setSaving(true);
        try {
            const res = await api.post(`/api/concord/rooms/${room.id}/invites`, {
                email: email.trim(),
                role,
            });
            setEmail('');
            toast(`Invited ${res.invite.email} (${res.invite.role})`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSaving(false);
        }
    };

    const handleRemoveInvite = async (inviteId) => {
        try {
            await api.delete(`/api/concord/rooms/${room.id}/invites/${inviteId}`);
            toast('Removed email invite');
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        }
    };

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <div>
                        <div className="concord-modal-kicker">CANVA / GOOGLE DOCS ACCESS CONTROL</div>
                        <h3 className="concord-modal-title">Share “{room.title}”</h3>
                    </div>
                    <button type="button" className="btn" onClick={onClose}>✕ Close</button>
                </div>

                <form className="concord-invite-form" onSubmit={handleInviteEmail}>
                    <label className="concord-label">INVITE PEOPLE OR AI ACCOUNTS BY EMAIL</label>
                    <div className="concord-invite-row">
                        <input
                            type="email"
                            className="input"
                            placeholder="teammate@company.com or partner@account.dev"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                        <select className="select concord-role-select" value={role} onChange={(e) => setRole(e.target.value)}>
                            <option value="editor">Can edit</option>
                            <option value="viewer">Can view</option>
                        </select>
                        <button type="submit" className="btn btn-emerald" disabled={saving}>
                            + Invite
                        </button>
                    </div>
                </form>

                <div className="concord-people-section">
                    <div className="concord-label">PEOPLE WITH ACCESS ({(room.invites?.length ?? 0) + 1})</div>
                    <div className="concord-people-list">
                        <div className="concord-person-row">
                            <div className="concord-person-avatar owner">👑</div>
                            <div className="concord-person-info">
                                <div className="concord-person-name">{room.owner_name || 'Room Owner'} (You)</div>
                                <div className="concord-person-email">{room.owner_email || 'bryankunhahaha@gmail.com'}</div>
                            </div>
                            <span className="concord-role-pill owner">Owner</span>
                        </div>

                        {(room.invites || []).map((inv) => {
                            const personalUrl = `${window.location.origin}/?invite=${inv.invite_token}`;
                            return (
                                <div key={inv.id} className="concord-person-row">
                                    <div className="concord-person-avatar">{inv.email.slice(0, 1).toUpperCase()}</div>
                                    <div className="concord-person-info">
                                        <div className="concord-person-name">{inv.email}</div>
                                        <div className="concord-person-email">
                                            {inv.accepted_at ? 'Joined room' : 'Pending invite link'} · {inv.role === 'editor' ? 'Can edit & connect local agent' : 'View only'}
                                        </div>
                                    </div>
                                    <div className="concord-person-actions">
                                        <button
                                            type="button"
                                            className="btn btn-xs"
                                            onClick={() => copyText(personalUrl, `Copied personal invite link for ${inv.email}`)}
                                        >
                                            🔗 Copy Personal Link
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-xs btn-danger"
                                            onClick={() => handleRemoveInvite(inv.id)}
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="concord-access-section">
                    <div className="concord-label">GENERAL LINK ACCESS</div>
                    <div className="concord-access-grid">
                        {ACCESS_MODES.map((mode) => {
                            const active = room.access_mode === mode.id;
                            return (
                                <button
                                    key={mode.id}
                                    type="button"
                                    className={`concord-access-card${active ? ' active' : ''}`}
                                    onClick={() => handleAccessModeChange(mode.id)}
                                    disabled={saving}
                                >
                                    <span className="concord-access-icon">{mode.icon}</span>
                                    <div className="concord-access-body">
                                        <div className="concord-access-title">{mode.title}</div>
                                        <div className="concord-access-sub">{mode.subtitle}</div>
                                    </div>
                                    {active && <span className="concord-access-check">✓ Active</span>}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="concord-share-footer">
                    <input type="text" className="input concord-share-url-input" value={shareUrl} readOnly />
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => copyText(shareUrl, 'Copied shareable group chat link to clipboard!')}
                    >
                        🔗 Copy Link
                    </button>
                </div>
            </div>
        </div>
    );
}
