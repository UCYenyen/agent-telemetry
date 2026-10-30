import { useState } from 'react';
import { api } from '../../lib/api';
import { useApp } from '../../context/AppContext';
import { timeAgo, useNow } from './AgentBoard';

export default function SharedMemoryPanel({ room, canEdit, onUpdated }) {
    const { toast } = useApp();
    const [noteKey, setNoteKey] = useState('');
    const [noteText, setNoteText] = useState('');
    const [saving, setSaving] = useState(false);
    const now = useNow(30000);

    if (!room) {
        return null;
    }

    const memory = (Array.isArray(room.shared_memory) ? room.shared_memory : [])
        .filter((item) => item && item.key)
        .sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')));
    const claims = room.active_claims || [];

    const pin = async (event) => {
        event.preventDefault();
        if (!noteText.trim() || !canEdit) return;
        setSaving(true);
        try {
            await api.patch(`/api/concord/rooms/${room.id}/memory`, {
                key: noteKey.trim() || `note_${Date.now()}`,
                category: 'decision',
                content: noteText.trim(),
                author_agent: 'Pinned by hand',
                author_user: 'Room member',
            });
            setNoteKey('');
            setNoteText('');
            toast('Saved to shared memory');
            onUpdated?.();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="memory-panel">
            {canEdit && (
                <form className="memory-form" onSubmit={pin}>
                    <input type="text" className="input" aria-label="Memory key" placeholder="Key, e.g. api_contract" value={noteKey} onChange={(e) => setNoteKey(e.target.value)} />
                    <textarea className="input" rows={2} aria-label="Memory note" placeholder="Decision, contract or context every agent should know" value={noteText} onChange={(e) => setNoteText(e.target.value)} />
                    <button type="submit" className="btn btn-sm btn-primary" disabled={saving || !noteText.trim()}>
                        {saving ? 'Saving…' : 'Save to memory'}
                    </button>
                </form>
            )}

            {memory.length === 0 ? (
                <p className="chat-empty">Nothing in shared memory yet. Agents add entries with write_shared_memory.</p>
            ) : (
                <ol className="memory-list">
                    {memory.map((item) => (
                        <li key={item.id || item.key} className="memory-item">
                            <div className="memory-item-head">
                                <code>{item.key}</code>
                                <time dateTime={item.updated_at}>{timeAgo(item.updated_at, now)}</time>
                            </div>
                            <p>{item.content}</p>
                            <span className="memory-author">{item.author_user && item.author_user !== item.author_agent ? `${item.author_agent} · ${item.author_user}` : item.author_agent}</span>
                        </li>
                    ))}
                </ol>
            )}

            {claims.length > 0 && (
                <>
                    <h4 className="memory-subhead">File claims</h4>
                    <ol className="memory-list">
                        {claims.map((claim, index) => (
                            <li key={index} className="memory-item">
                                <div className="memory-item-head">
                                    <strong>{claim.agent_name || claim.agent}</strong>
                                    <span>{claim.device_name || claim.device}</span>
                                </div>
                                <div className="agent-files">
                                    {(claim.files || (claim.file ? [claim.file] : [])).map((file) => (
                                        <code key={file} className="agent-file">{file}</code>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </div>
    );
}
