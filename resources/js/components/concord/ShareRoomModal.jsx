import { useState } from 'react';
import { api } from '../../lib/api';
import { useEscapeKey } from '../../hooks/useEscapeKey';
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
        subtitle: 'Anyone with the link can view as no_role (read-only). Only Admin and invited members with role team_member can connect MCP and prompt.',
    },
    {
        id: 'link_edit',
        badge: '[LINK EDIT]',
        title: 'Anyone with the link - Can edit (Bypasses Role Check)',
        subtitle: 'Per-project role check is bypassed: anyone opening this link is granted team_member edit capabilities to connect their MCP and prompt.',
    },
];

const DEFAULT_ROLES_CATALOG = [
    {
        id: 'admin',
        title: 'admin (Platform & Project Owner)',
        description: 'Full control over all 3 capabilities: (1) Can view and control live VPS Claude / Antigravity Fleet sessions & Council, (2) Can create/delete Group Chat projects and invite unlimited users or assign per-project roles (team_member or no_role), and (3) Can view and edit all Group Chat projects.',
    },
    {
        id: 'team_member',
        title: 'team_member (Project Editor & Active Group Chat User)',
        description: 'Can both view and edit inside the assigned Group Chat project: connect their local MCP agent first, send prompts, coordinate multi-agent handoffs across unlimited computers, and update VPS Shared Memory.',
    },
    {
        id: 'no_role',
        title: 'no_role (Default - Read-Only Group Chat Viewer)',
        description: 'Default role assigned to all users. Can only view the Group Chat in read-only mode (watch live agent talks, reasoning steps, and shared memory). Cannot connect an editing MCP or send prompts unless upgraded to team_member by Admin or the project link mode is set to Anyone with the link can edit.',
    },
];

export default function ShareRoomModal({ open, room, onClose, onUpdated }) {
    const { toast } = useApp();
    const [rows, setRows] = useState([
        { email: '', name: '', role: 'team_member' },
        { email: '', name: '', role: 'team_member' },
        { email: '', name: '', role: 'no_role' },
    ]);
    const [bulkText, setBulkText] = useState('');
    const [bulkRole, setBulkRole] = useState('team_member');
    const [saving, setSaving] = useState(false);

    useEscapeKey(open && room, onClose);

    if (!open || !room) {
        return null;
    }

    const canManageRoom = Boolean(room.can_manage_room || room.global_role === 'admin');
    const shareUrl = room.share_url || `${window.location.origin}/?room=${room.share_token}`;
    const rolesCatalog = room.roles_catalog?.length ? room.roles_catalog : DEFAULT_ROLES_CATALOG;
    const platformUsers = room.platform_users || [];
    const invites = room.invites || [];
    const participants = room.participants || [];

    const copyText = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            toast(label);
        } catch {
            toast(`Copy: ${text}`);
        }
    };

    const handleRowChange = (index, field, value) => {
        setRows((prev) => prev.map((r, i) => (i === index ? { ...r, [field]: value } : r)));
    };

    const handleAddRow = () => {
        setRows((prev) => [...prev, { email: '', name: '', role: 'team_member' }]);
    };

    const handleRemoveRow = (index) => {
        setRows((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== index) : prev));
    };

    const handleBatchInviteRows = async (event) => {
        event.preventDefault();
        if (!canManageRoom) return;
        const validRows = rows
            .map((r) => ({ email: r.email.trim(), name: r.name.trim() || undefined, role: r.role }))
            .filter((r) => r.email !== '');

        if (validRows.length === 0) {
            toast('Enter at least one email or username to add.');
            return;
        }

        setSaving(true);
        try {
            const res = await api.post(`/api/concord/rooms/${room.id}/invites`, {
                invites: validRows,
            });
            setRows([
                { email: '', name: '', role: 'team_member' },
                { email: '', name: '', role: 'team_member' },
            ]);
            toast(`Added/updated ${res.count || validRows.length} user(s) in "${room.title}"!`);
            onUpdated?.();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const handleBulkInviteSubmit = async (event) => {
        event.preventDefault();
        if (!bulkText.trim() || !canManageRoom) return;
        setSaving(true);
        try {
            const res = await api.post(`/api/concord/rooms/${room.id}/invites`, {
                email: bulkText.trim(),
                role: bulkRole,
            });
            setBulkText('');
            toast(`Bulk added ${res.count || 1} user(s) with role ${bulkRole}!`);
            onUpdated?.();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const handleQuickAddPlatformUser = async (u, targetRole) => {
        if (!canManageRoom) return;
        setSaving(true);
        try {
            await api.post(`/api/concord/rooms/${room.id}/invites`, {
                invites: [
                    {
                        email: u.email,
                        name: u.name || u.username,
                        role: targetRole,
                    },
                ],
            });
            toast(`Added ${u.name || u.email} to project with role: ${targetRole}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setSaving(false);
        }
    };

    const handleAccessModeChange = async (nextMode) => {
        if (!canManageRoom || nextMode === room.access_mode) return;
        if (nextMode === 'link_edit' && !window.confirm('Let anyone with the link edit this group chat? They can prompt agents and change shared memory without an invite or role.')) {
            return;
        }
        setSaving(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}`, {
                access_mode: nextMode,
            });
            toast(`Link access updated to ${nextMode}`);
            onUpdated?.();
        } catch (err) {
            toast(err.message, 'error');
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
            toast(err.message, 'error');
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
            toast(err.message, 'error');
        }
    };

    return (
        <div className="concord-modal-backdrop" onClick={onClose}>
            <div className="concord-modal concord-modal-wide" role="dialog" aria-modal="true" aria-labelledby="concord-share-title" onClick={(e) => e.stopPropagation()}>
                <div className="concord-modal-head">
                    <div>
                        <h3 className="concord-modal-title" id="concord-share-title">Members and roles: {room.title}</h3>
                    </div>
                    <button type="button" className="btn" onClick={onClose}>Close</button>
                </div>

                {/* Detailed 3-Role Reference Guide */}
                <div className="concord-people-section" style={{ marginBottom: '1rem' }}>
                    <div className="concord-label">ROLE PERMISSIONS SPECIFICATION (DEFAULT ROLE: no_role // UNLIMITED USERS SUPPORTED)</div>
                    <div className="concord-roles-matrix-grid">
                        {rolesCatalog.map((r) => (
                            <div key={r.id} className="concord-role-spec-card">
                                <span className={`concord-role-pill ${r.id === 'admin' ? 'owner' : ''}`}>{r.id.toUpperCase()}</span>
                                <div className="concord-access-title" style={{ marginTop: '0.35rem' }}>{r.title}</div>
                                <div className="concord-access-sub" style={{ marginTop: '0.2rem' }}>{r.description}</div>
                            </div>
                        ))}
                    </div>
                </div>

                {canManageRoom ? (
                    <div className="concord-connect-grid" style={{ marginBottom: '1rem' }}>
                        {/* Left: Multi-Row Builder + Bulk Paste */}
                        <div className="concord-connect-card">
                            <form onSubmit={handleBatchInviteRows}>
                                <div className="concord-section-head" style={{ marginBottom: '0.5rem' }}>
                                    <span className="concord-label" style={{ marginBottom: 0 }}>
                                        ADMIN // ADD MULTIPLE USERS AT ONCE (UNLIMITED)
                                    </span>
                                    <button type="button" className="btn btn-xs" onClick={handleAddRow}>
                                        + Add Another User Row
                                    </button>
                                </div>
                                <div className="concord-multi-invite-rows">
                                    {rows.map((row, idx) => (
                                        <div key={idx} className="concord-multi-invite-row">
                                            <input
                                                type="text"
                                                className="input"
                                                placeholder={`User ${idx + 1} email or username...`}
                                                value={row.email}
                                                onChange={(e) => handleRowChange(idx, 'email', e.target.value)}
                                            />
                                            <input
                                                type="text"
                                                className="input"
                                                placeholder="Display name (opt)"
                                                value={row.name}
                                                onChange={(e) => handleRowChange(idx, 'name', e.target.value)}
                                            />
                                            <select
                                                className="select"
                                                value={row.role}
                                                onChange={(e) => handleRowChange(idx, 'role', e.target.value)}
                                            >
                                                <option value="team_member">team_member (Can Edit)</option>
                                                <option value="no_role">no_role (View Only)</option>
                                            </select>
                                            {rows.length > 1 && (
                                                <button
                                                    type="button"
                                                    className="btn btn-xs btn-danger"
                                                    onClick={() => handleRemoveRow(idx)}
                                                >
                                                    x
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                                <button type="submit" className="btn btn-emerald" style={{ width: '100%', marginTop: '0.55rem' }} disabled={saving}>
                                    {saving ? 'Adding Users...' : `+ Add All Filled Users to Project`}
                                </button>
                            </form>

                            <form onSubmit={handleBulkInviteSubmit} style={{ marginTop: '0.85rem', paddingTop: '0.75rem', borderTop: '1px solid rgba(255,255,255,0.07)' }}>
                                <label className="concord-label">BULK PASTE EMAILS OR USERNAMES (COMMA OR SPACE SEPARATED)</label>
                                <div className="concord-invite-row" style={{ marginBottom: 0 }}>
                                    <input
                                        type="text"
                                        className="input"
                                        placeholder="user1@team.com, user2@team.com, user3@team.com, user4@team.com..."
                                        value={bulkText}
                                        onChange={(e) => setBulkText(e.target.value)}
                                    />
                                    <select className="select" value={bulkRole} onChange={(e) => setBulkRole(e.target.value)}>
                                        <option value="team_member">team_member</option>
                                        <option value="no_role">no_role</option>
                                    </select>
                                    <button type="submit" className="btn btn-primary" disabled={saving}>
                                        Bulk Add
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Right: Registered Platform Accounts Directory (1-Click Add) */}
                        <div className="concord-connect-card">
                            <div className="concord-label">
                                REGISTERED PLATFORM USERS DIRECTORY ({platformUsers.length}) — 1-CLICK ADD TO PROJECT
                            </div>
                            <div className="concord-people-list" style={{ maxHeight: '260px', overflowY: 'auto', marginBottom: 0 }}>
                                {platformUsers.length === 0 ? (
                                    <div className="concord-empty-box">No registered user accounts found.</div>
                                ) : (
                                    platformUsers.map((u) => {
                                        const existingInvite = invites.find(
                                            (inv) => String(inv.email || '').toLowerCase() === String(u.email || '').toLowerCase()
                                        );
                                        const isOwner = String(room.owner_email || '').toLowerCase() === String(u.email || '').toLowerCase();
                                        const currentProjRole = isOwner
                                            ? 'admin'
                                            : existingInvite
                                              ? (existingInvite.role === 'editor' ? 'team_member' : existingInvite.role)
                                              : null;

                                        return (
                                            <div key={u.id} className="concord-person-row">
                                                <div className="concord-person-avatar">
                                                    {(u.name || u.username || u.email || 'U').slice(0, 2).toUpperCase()}
                                                </div>
                                                <div className="concord-person-info">
                                                    <div className="concord-person-name">
                                                        {u.name} <code>@{u.username}</code>
                                                    </div>
                                                    <div className="concord-person-email">
                                                        {u.email} // Platform Role: <strong>{u.role}</strong>
                                                        {currentProjRole ? ` // In Project: ${currentProjRole}` : ''}
                                                    </div>
                                                </div>
                                                <div className="concord-person-actions">
                                                    {isOwner ? (
                                                        <span className="concord-role-pill owner">Owner (admin)</span>
                                                    ) : (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className={`btn btn-xs ${currentProjRole === 'team_member' ? 'btn-emerald' : ''}`}
                                                                onClick={() => handleQuickAddPlatformUser(u, 'team_member')}
                                                                disabled={saving}
                                                            >
                                                                {currentProjRole === 'team_member' ? 'team_member [Active]' : '+ team_member'}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className={`btn btn-xs ${currentProjRole === 'no_role' ? 'btn-primary' : ''}`}
                                                                onClick={() => handleQuickAddPlatformUser(u, 'no_role')}
                                                                disabled={saving}
                                                            >
                                                                {currentProjRole === 'no_role' ? 'no_role [Active]' : '+ no_role'}
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="concord-arch-pill" style={{ marginBottom: '1rem' }}>
                        Your effective role in this project is <strong>{room.project_role || 'no_role'}</strong>. Only an <strong>admin</strong> can invite members or change project roles.
                    </div>
                )}

                <div className="concord-people-section">
                    <div className="concord-label">PROJECT MEMBERS & MCP CONNECTION STATUS ({invites.length + 1} TOTAL MEMBERS)</div>
                    <div className="concord-people-list">
                        <div className="concord-person-row">
                            <div className="concord-person-avatar owner">AD</div>
                            <div className="concord-person-info">
                                <div className="concord-person-name">{room.owner_name || 'Project Admin'}</div>
                                <div className="concord-person-email">{room.owner_email || 'bryankunhahaha@gmail.com'} - Full Admin Control</div>
                            </div>
                            <span className="concord-role-pill owner">admin</span>
                        </div>

                        {invites.map((inv) => {
                            const personalUrl = `${window.location.origin}/?invite=${inv.invite_token}`;
                            const effectiveRole = inv.role === 'editor' ? 'team_member' : inv.role === 'viewer' ? 'no_role' : inv.role;
                            const hasMcp = participants.some(
                                (p) => String(p.account_email || '').toLowerCase() === String(inv.email || '').toLowerCase()
                            );
                            return (
                                <div key={inv.id} className="concord-person-row">
                                    <div className="concord-person-avatar">{(inv.name || inv.email).slice(0, 2).toUpperCase()}</div>
                                    <div className="concord-person-info">
                                        <div className="concord-person-name">
                                            {inv.name || inv.email} <span style={{ color: 'var(--muted)', fontWeight: 400 }}>({inv.email})</span>
                                        </div>
                                        <div className="concord-person-email">
                                            Role: <strong>{effectiveRole}</strong> // MCP Status:{' '}
                                            <strong style={{ color: hasMcp ? 'var(--emerald)' : 'var(--amber)' }}>
                                                {hasMcp ? 'MCP CONNECTED (CAN PROMPT)' : 'MCP NOT CONNECTED YET'}
                                            </strong>
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

