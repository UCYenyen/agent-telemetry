import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';

export default function SharedMemoryPanel({ room, activeToken, canEdit, onUpdated }) {
    const { toast } = useApp();
    const [noteKey, setNoteKey] = useState('');
    const [noteText, setNoteText] = useState('');
    const [savingNote, setSavingNote] = useState(false);

    if (!room) {
        return null;
    }

    const rawMemory = room.shared_memory || [];
    const memoryItems = Array.isArray(rawMemory)
        ? rawMemory
        : [
              ...Object.entries(rawMemory.architecture || {}).map(([k, v]) => ({ key: k, category: 'architecture', content: String(v), author_agent: 'System' })),
              ...Object.entries(rawMemory.contracts || {}).map(([k, v]) => ({ key: k, category: 'contract', content: String(v), author_agent: 'System' })),
              ...(rawMemory.decisions || []).map((d, i) => ({ key: `decision_${i + 1}`, category: 'decision', content: d.summary || String(d), author_agent: d.author || 'Agent' })),
          ];

    const claims = room.active_claims || [];
    const localTasks = room.local_tasks || room.localTasks || [];
    const token = activeToken || room.share_token;

    const handleAddDecision = async (event) => {
        event.preventDefault();
        if (!noteText.trim() || !canEdit) return;
        setSavingNote(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}/memory`, {
                key: noteKey.trim() || `decision_${Date.now()}`,
                category: 'decision',
                content: noteText.trim(),
                author_agent: 'Shared Memory Pin',
                author_user: 'Project Collaborator',
            });
            setNoteKey('');
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
                result_summary: `Executed local prompt and applied file changes directly on ${task.target_device} (${task.target_agent_name || 'Local Agent'}).`,
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
                    <span className="concord-section-title">LOCAL COMPUTER EDIT QUEUE</span>
                    <span className="concord-badge">{localTasks.length} TASKS</span>
                </div>
                <div className="concord-section-sub">
                    VPS coordinates the context window; file edits execute on each user local computer.
                </div>

                <div className="concord-task-list">
                    {localTasks.length === 0 ? (
                        <div className="concord-empty-box">No local computer tasks queued yet. Send a group chat prompt to trigger cross-device handoffs.</div>
                    ) : (
                        localTasks.slice(0, 8).map((task) => (
                            <div key={task.id} className={`concord-task-card status-${task.status}`}>
                                <div className="concord-task-top">
                                    <span className="concord-task-device">PC: {task.target_device}</span>
                                    <span className={`concord-task-status status-${task.status}`}>
                                        {task.status === 'completed' ? '[APPLIED ON PC]' : task.status === 'executing_on_device' ? '[RUNNING ON PC]' : '[QUEUED FOR PC]'}
                                    </span>
                                </div>
                                <div className="concord-task-title">{task.title || task.target_agent_name || 'Local Workspace Edit'}</div>
                                <div className="concord-task-meta">
                                    <span>Agent: <strong>{task.target_agent_name || task.target_user_name}</strong></span>
                                    {task.from_sender && <span>From: {task.from_sender}</span>}
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
                                        Confirm Applied on {task.target_device}
                                    </button>
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            <div className="concord-panel-section">
                <div className="concord-section-head">
                    <span className="concord-section-title">VPS SHARED MEMORY BANK</span>
                    <span className="concord-badge emerald">LIVE SYNC</span>
                </div>

                {canEdit && (
                    <form className="concord-pin-form" onSubmit={handleAddDecision} style={{ flexDirection: 'column' }}>
                        <input
                            type="text"
                            className="input"
                            placeholder="Memory key (e.g. api_contract)..."
                            value={noteKey}
                            onChange={(e) => setNoteKey(e.target.value)}
                        />
                        <div style={{ display: 'flex', gap: '0.4rem' }}>
                            <input
                                type="text"
                                className="input"
                                placeholder="Pin shared contract or memory note..."
                                value={noteText}
                                onChange={(e) => setNoteText(e.target.value)}
                            />
                            <button type="submit" className="btn btn-xs btn-primary" disabled={savingNote}>
                                + Pin
                            </button>
                        </div>
                    </form>
                )}

                <div className="concord-decisions-list">
                    {memoryItems.slice(0, 10).map((item, idx) => (
                        <div key={item.id || idx} className="concord-decision-item">
                            <div className="concord-decision-author">[{item.category || 'memory'}] {item.key} - by {item.author_agent || 'System'}</div>
                            <div className="concord-decision-summary">{item.content}</div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="concord-panel-section">
                <div className="concord-section-head">
                    <span className="concord-section-title">CROSS-DEVICE FILE CLAIMS</span>
                    <span className="concord-badge">{claims.length} LOCKED</span>
                </div>
                <div className="concord-claims-list">
                    {claims.length === 0 ? (
                        <div className="concord-empty-box">No active file locks across user computers.</div>
                    ) : (
                        claims.map((claim, idx) => (
                            <div key={idx} className="concord-claim-card">
                                <div className="concord-claim-owner">
                                    <strong>{claim.agent_name || claim.agent}</strong> - <span>{claim.device_name || claim.device}</span>
                                </div>
                                <div className="concord-task-files">
                                    {(claim.files || (claim.file ? [claim.file] : [])).map((f, i) => (
                                        <code key={i} className="concord-file-chip">{f}</code>
                                    ))}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </aside>
    );
}
