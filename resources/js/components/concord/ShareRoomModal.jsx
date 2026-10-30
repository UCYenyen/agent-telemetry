import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

const ACCESS_MODES = [
    {
        id: 'invite_only',
        badge: '[RESTRICTED]',
        title: 'Restricted (Invite Only - Enforces Project Role)',
        subtitle: 'Only users invited by Admin can open this project. Checks per-project role: team_member can edit & use Group Chat; no_role can only view.',
    },
    {
        id: 'link_view',
        badge: '[LINK VIEW]',
        title: 'Anyone with the link - Can view (Enforces Project Role for Editing)',
        subtitle: 'Anyone with the link can view as no_role (read-only). Only Admin and invited members with role team_member can edit and send prompts.',
    },
    {
        id: 'link_edit',
        badge: '[LINK EDIT]',
        title: 'Anyone with the link - Can edit (Bypasses Role Check)',
        subtitle: 'Per-project role check is bypassed: anyone opening this link is granted team_member edit capabilities to prompt and connect their local PC.',
    },
];

const DEFAULT_ROLES_CATALOG = [
    {
        id: 'admin',
        title: 'admin (Platform & Project Owner)',
        description: 'Full control over all 3 capabilities: (1) Can view and control live VPS Claude / Antigravity Fleet sessions & Council, (2) Can create/delete Group Chat projects and invite users or assign per-project roles (team_member or no_role), and (3) Can view and edit all Group Chat projects.',
    },
    {
        id: 'team_member',
        title: 'team_member (Project Editor & Active Group Chat User)',
        description: 'Can both view and edit inside the assigned Group Chat project: send prompts, coordinate multi-agent handoffs, connect their local computer / CLI agent so code changes run on their PC, and update VPS Shared Memory. Cannot view Admin live VPS Fleet sessions or create new projects.',
    },
    {
        id: 'no_role',
        title: 'no_role (Default - Read-Only Group Chat Viewer)',
        description: 'Default role assigned to all users. Can only view the Group Chat in read-only mode (watch live agent talks, reasoning steps, and shared memory). Cannot send prompts or edit unless upgraded to team_member by Admin or the project link mode is set to Anyone with the link can edit.',
    },
];

export default function ShareRoomModal({ open, room, onClose, onUpdated }) {
    const { toast } = useApp();
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('no_role');
    const [saving, setSaving] = useState(false);

    if (!open || !room) {
        return null;
    }

    const canManageRoom = Boolean(room.can_manage_room || room.global_role === 'admin');
    const shareUrl = room.share_url || `${window.location.origin}/?room=${room.share_token}`;
    const rolesCatalog = room.roles_catalog?.length ? room.roles_catalog : DEFAULT_ROLES_CATALOG;

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    const handleAccessModeChange = async (nextMode) => {
        if (!canManageRoom) return;
        setSaving(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}`, {
                access_mode: nextMode,
            });
            toast(`Link access updated to ${nextMode}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSaving(false);
        }
    };

    const handleInviteEmail = async (event) => {
        event.preventDefault();
        if (!email.trim() || !canManageRoom) return;
        setSaving(true);
        try {
            const res = await api.post(`/api/concord/rooms/${room.id}/invites`, {
                email: email.trim(),
                role,
            });
            const invited = res.invite || res;
            setEmail('');
            toast(`Invited ${invited.email} with project role: ${invited.role}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSaving(false);
        }
    };

    const handleRoleChange = async (inviteId, nextRole) => {
        if (!canManageRoom) return;
        setSaving(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}/invites/${inviteId}`, {
                role: nextRole,
            });
            toast(`Updated member project role to ${nextRole}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSaving(false);
        }
    };

    const handleRemoveInvite = async (inviteId) => {
        if (!canManageRoom) return;
        try {
            await api.delete(`/api/concord/rooms/${room.id}/invites/${inviteId}`);
            toast('Removed member from project');
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        }
    };

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal concord-modal-wide" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <div>
                        <div className="concord-modal-kicker">PROJECT SHARING & PER-PROJECT ROLE MANAGEMENT</div>
                        <h3 className="concord-modal-title">Share & Manage Roles: "{room.title}"</h3>
                    </div>
                    <button type="button" className="btn" onClick={onClose}>Close</button>
                </div>

                {/* Detailed 3-Role Reference Guide */}
                <div className="concord-people-section" style={{ marginBottom: '1rem' }}>
                    <div className="concord-label">ROLE PERMISSIONS SPECIFICATION (DEFAULT ROLE: no_role)</div>
                    <div className="concord-access-grid">
                        {rolesCatalog.map((r) => (
                            <div key={r.id} className="concord-access-card" style={{ cursor: 'default' }}>
                                <span className="concord-role-pill">{r.id.toUpperCase()}</span>
                                <div className="concord-access-body">
                                    <div className="concord-access-title">{r.title}</div>
                                    <div className="concord-access-sub">{r.description}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {canManageRoom ? (
                    <form className="concord-invite-form" onSubmit={handleInviteEmail}>
                        <label className="concord-label">ADMIN ONLY // INVITE USER BY EMAIL & SET PROJECT ROLE</label>
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
                                <option value="no_role">no_role (Default - View Only)</option>
                                <option value="team_member">team_member (Can Edit & Use Chat)</option>
                            </select>
                            <button type="submit" className="btn btn-emerald" disabled={saving}>
                                + Invite Member
                            </button>
                        </div>
                    </form>
                ) : (
                    <div className="concord-arch-pill" style={{ marginBottom: '1rem' }}>
                        Your effective role in this project is <strong>{room.project_role || 'no_role'}</strong>. Only an <strong>admin</strong> can invite members or change project roles.
                    </div>
                )}

                <div className="concord-people-section">
                    <div className="concord-label">PROJECT MEMBERS & ASSIGNED ROLES ({(room.invites?.length ?? 0) + 1})</div>
                    <div className="concord-people-list">
                        <div className="concord-person-row">
                            <div className="concord-person-avatar owner">AD</div>
                            <div className="concord-person-info">
                                <div className="concord-person-name">{room.owner_name || 'Project Admin'}</div>
                                <div className="concord-person-email">{room.owner_email || 'bryankunhahaha@gmail.com'} - Full Admin Control</div>
                            </div>
                            <span className="concord-role-pill owner">admin</span>
                        </div>

                        {(room.invites || []).map((inv) => {
                            const personalUrl = `${window.location.origin}/?invite=${inv.invite_token}`;
                            const effectiveRole = inv.role === 'editor' ? 'team_member' : inv.role === 'viewer' ? 'no_role' : inv.role;
                            return (
                                <div key={inv.id} className="concord-person-row">
                                    <div className="concord-person-avatar">{inv.email.slice(0, 2).toUpperCase()}</div>
                                    <div className="concord-person-info">
                                        <div className="concord-person-name">{inv.email}</div>
                                        <div className="concord-person-email">
                                            {inv.accepted_at ? 'Joined project' : 'Invited'} - Role: {effectiveRole} ({effectiveRole === 'team_member' ? 'Can view & edit Group Chat' : 'View only Group Chat'})
                                        </div>
                                    </div>
                                    <div className="concord-person-actions">
                                        {canManageRoom ? (
                                            <select
                                                className="select"
                                                style={{ fontSize: '0.72rem', padding: '0.25rem 0.5rem' }}
                                                value={effectiveRole}
                                                onChange={(e) => handleRoleChange(inv.id, e.target.value)}
                                                disabled={saving}
                                            >
                                                <option value="no_role">no_role (View Only)</option>
                                                <option value="team_member">team_member (Can Edit)</option>
                                            </select>
                                        ) : (
                                            <span className="concord-role-pill">{effectiveRole}</span>
                                        )}
                                        <button
                                            type="button"
                                            className="btn btn-xs"
                                            onClick={() => copyText(personalUrl, `Copied personal invite link for ${inv.email}`)}
                                        >
                                            Copy Invite Link
                                        </button>
                                        {canManageRoom && (
                                            <button
                                                type="button"
                                                className="btn btn-xs btn-danger"
                                                onClick={() => handleRemoveInvite(inv.id)}
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="concord-access-section">
                    <div className="concord-label">GENERAL LINK ACCESS MODE {room.access_mode === 'link_edit' ? '(ROLE BYPASS ACTIVE)' : '(PER-PROJECT ROLE CHECK ACTIVE)'}</div>
                    <div className="concord-access-grid">
                        {ACCESS_MODES.map((mode) => {
                            const active = room.access_mode === mode.id;
                            return (
                                <button
                                    key={mode.id}
                                    type="button"
                                    className={`concord-access-card${active ? ' active' : ''}`}
                                    onClick={() => handleAccessModeChange(mode.id)}
                                    disabled={saving || !canManageRoom}
                                >
                                    <span className="concord-access-icon" style={{ fontFamily: 'var(--mono)', fontSize: '0.7rem' }}>{mode.badge}</span>
                                    <div className="concord-access-body">
                                        <div className="concord-access-title">{mode.title}</div>
                                        <div className="concord-access-sub">{mode.subtitle}</div>
                                    </div>
                                    {active && <span className="concord-access-check">[ACTIVE]</span>}
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
                        Copy Share Link
                    </button>
                </div>
            </div>
        </div>
    );
}
