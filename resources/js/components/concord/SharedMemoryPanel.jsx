import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

export default function SharedMemoryPanel({ room, activeToken, canEdit, onUpdated }) {
    const { toast } = useApp();
    const [noteText, setNoteText] = useState('');
    const [savingNote, setSavingNote] = useState(false);

    if (!room) {
        return null;
    }

    const memory = room.shared_memory || {};
    const architecture = memory.architecture || {};
    const contracts = memory.contracts || {};
    const decisions = memory.decisions || [];
    const claims = room.active_claims || [];
    const localTasks = room.local_tasks || [];
    const token = activeToken || room.share_token;

    const handleAddDecision = async (event) => {
        event.preventDefault();
        if (!noteText.trim() || !canEdit) return;
        setSavingNote(true);
        try {
            const nextDecisions = [
                {
                    author: 'Shared Memory Pin',
                    summary: noteText.trim(),
                    timestamp: new Date().toISOString(),
                },
                ...decisions,
            ].slice(0, 20);

            await api.patch(`/api/concord/rooms/${room.id}/memory`, {
                shared_memory: {
                    ...memory,
                    decisions: nextDecisions,
                },
                author: 'Shared Memory Pin',
                summary: `Pinned to VPS Shared Memory: "${noteText.trim()}"`,
            });
            setNoteText('');
            toast('Pinned decision to VPS Shared Memory Bank');
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        } finally {
            setSavingNote(false);
        }
    };

    const handleApplyLocalTask = async (task) => {
        try {
            await api.post(`/api/concord/shared/${token}/tasks/${task.id}/complete`, {
                status: 'completed',
                modified_files: task.modified_files || [],
                result_summary: `Executed local prompt and applied file changes directly on ${task.target_device} (${task.target_user_name}).`,
            });
            toast(`Applied queued edits on ${task.target_device}!`);
            onUpdated?.();
        } catch (err) {
            toast(err.message);
        }
    };

    return (
        <aside className="concord-memory-panel">
            <div className="concord-panel-section">
                <div className="concord-section-head">
                    <span className="concord-section-title">💻 LOCAL COMPUTER EDIT QUEUE</span>
                    <span className="concord-badge">{localTasks.length} TASKS</span>
                </div>
                <div className="concord-section-sub">
                    VPS coordinates the context window; file edits execute on each user’s own local computer.
                </div>

                <div className="concord-task-list">
                    {localTasks.length === 0 ? (
                        <div className="concord-empty-box">No local computer tasks queued yet. Send a group chat prompt to trigger cross-device handoffs.</div>
                    ) : (
                        localTasks.slice(0, 8).map((task) => (
                            <div key={task.id} className={`concord-task-card status-${task.status}`}>
                                <div className="concord-task-top">
                                    <span className="concord-task-device">💻 {task.target_device}</span>
                                    <span className={`concord-task-status status-${task.status}`}>
                                        {task.status === 'completed' ? '✓ Applied on PC' : task.status === 'executing_on_device' ? '⚡ Running on PC' : '⏳ Queued for PC'}
                                    </span>
                                </div>
                                <div className="concord-task-title">{task.title}</div>
                                <div className="concord-task-meta">
                                    <span>Owner: <strong>{task.target_user_name}</strong></span>
                                    {task.initiated_by_agent && <span>From: {task.initiated_by_agent}</span>}
                                </div>
                                {Array.isArray(task.modified_files) && task.modified_files.length > 0 && (
                                    <div className="concord-task-files">
                                        {task.modified_files.map((file, idx) => (
                                            <code key={idx} className="concord-file-chip">{file}</code>
                                        ))}
                                    </div>
                                )}
                                {task.status !== 'completed' && canEdit && (
                                    <button
                                        type="button"
                                        className="btn btn-xs btn-emerald"
                                        style={{ marginTop: '0.45rem', width: '100%' }}
                                        onClick={() => handleApplyLocalTask(task)}
                                    >
                                        ⚡ Confirm Applied on {task.target_device}
                                    </button>
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            <div className="concord-panel-section">
                <div className="concord-section-head">
                    <span className="concord-section-title">🧠 VPS SHARED MEMORY BANK</span>
                    <span className="concord-badge emerald">LIVE SYNC</span>
                </div>

                <div className="concord-memory-kv">
                    {Object.entries(architecture).map(([k, v]) => (
                        <div key={k} className="concord-kv-row">
                            <span className="concord-kv-key">{k}</span>
                            <span className="concord-kv-val">{String(v)}</span>
                        </div>
                    ))}
                    {Object.entries(contracts).map(([k, v]) => (
                        <div key={k} className="concord-kv-row">
                            <span className="concord-kv-key">{k}</span>
                            <span className="concord-kv-val">{String(v)}</span>
                        </div>
                    ))}
                </div>

                {canEdit && (
                    <form className="concord-pin-form" onSubmit={handleAddDecision}>
                        <input
                            type="text"
                            className="input"
                            placeholder="Pin shared contract or memory note…"
                            value={noteText}
                            onChange={(e) => setNoteText(e.target.value)}
                        />
                        <button type="submit" className="btn btn-xs btn-primary" disabled={savingNote}>
                            + Pin
                        </button>
                    </form>
                )}

                <div className="concord-decisions-list">
                    {decisions.slice(0, 6).map((d, idx) => (
                        <div key={idx} className="concord-decision-item">
                            <div className="concord-decision-author">✻ {d.author}</div>
                            <div className="concord-decision-summary">{d.summary}</div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="concord-panel-section">
                <div className="concord-section-head">
                    <span className="concord-section-title">🛡️ CROSS-DEVICE FILE CLAIMS</span>
                    <span className="concord-badge">{claims.length} LOCKED</span>
                </div>
                <div className="concord-claims-list">
                    {claims.map((claim, idx) => (
                        <div key={idx} className="concord-claim-card">
                            <div className="concord-claim-owner">
                                <strong>{claim.agent}</strong> · <span>{claim.device}</span>
                            </div>
                            <div className="concord-task-files">
                                {(claim.files || []).map((f, i) => (
                                    <code key={i} className="concord-file-chip">{f}</code>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </aside>
    );
}
