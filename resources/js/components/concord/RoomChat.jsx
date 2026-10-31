import { useEffect, useRef, useState } from 'react';
import { timeAgo, useNow } from './AgentBoard';

function messageFiles(message) {
    const changes = message.local_changes;
    if (Array.isArray(changes)) return changes.map((c) => (typeof c === 'string' ? c : c.file)).filter(Boolean);
    return Array.isArray(changes?.files) ? changes.files : [];
}

function ChatMessage({ message, now }) {
    const kind = message.sender_kind || message.sender_type || 'agent';
    const isNote = kind === 'system' || kind === 'memory' || message.kind === 'system' || message.kind === 'memory_update';
    const text = message.content || message.body || '';
    const files = messageFiles(message);

    if (isNote) {
        return (
            <li className="chat-note">
                <span>{text}</span>
                <time dateTime={message.created_at}>{timeAgo(message.created_at, now)}</time>
            </li>
        );
    }

    return (
        <li className={`chat-msg${kind === 'human' ? ' human' : ''}`}>
            <div className="chat-msg-head">
                <strong>{message.sender_name}</strong>
                {message.sender_device && <span className="chat-msg-device">{message.sender_device}</span>}
                <time dateTime={message.created_at}>{timeAgo(message.created_at, now)}</time>
            </div>
            <p className="chat-msg-body">{text}</p>
            {files.length > 0 && (
                <div className="agent-files">
                    {files.map((file) => (
                        <code key={file} className="agent-file">{file}</code>
                    ))}
                </div>
            )}
        </li>
    );
}

// A prompt runs on an agent only when it comes from that agent's owner, signed in to Agent Host.
function deliveryHint(targetAgent, participants, signedInEmail) {
    const owns = (agent) => signedInEmail !== '' && String(agent.account_email || '').toLowerCase() === signedInEmail;
    if (!targetAgent) {
        const mine = participants.filter(owns).length;
        return mine > 0
            ? `Runs on your ${mine === 1 ? 'agent' : `${mine} agents`} when each finishes its current turn. Other people's agents get it as a message.`
            : "Sent as a message. Agents read it but won't run it unless it comes from their owner.";
    }
    return owns(targetAgent)
        ? `Runs in Claude Code on ${targetAgent.device_name} as soon as it finishes its current turn. The reply shows up here.`
        : `${targetAgent.user_name}'s agent gets this as a message. It won't run it automatically.`;
}

export default function RoomChat({ room, currentUser, target, onTargetChange, myParticipants, hasConnectedMcp, canEdit, sending, onSendPrompt, onConnect }) {
    const participants = room?.participants || [];
    const messages = room?.messages || [];
    const [text, setText] = useState('');
    const setTarget = onTargetChange;
    const targetAgent = participants.find((p) => String(p.id) === String(target)) || null;
    const signedInEmail = String(currentUser?.email || '').trim().toLowerCase();
    const feedRef = useRef(null);
    const now = useNow(30000);
    const sender = myParticipants[0] || null;

    useEffect(() => {
        if (feedRef.current) feedRef.current.scrollTop = feedRef.current.scrollHeight;
    }, [messages.length]);

    const submit = (event) => {
        event.preventDefault();
        const content = text.trim();
        if (!content || !sender || sending) return;
        onSendPrompt({
            content,
            body: content,
            participant_id: sender.id,
            sender_name: sender.user_name,
            sender_email: sender.account_email || null,
            sender_device: sender.device_name,
            target_agent: target,
        });
        setText('');
    };

    return (
        <div className="room-chat">
            <ol className="chat-feed" ref={feedRef} aria-live="polite">
                {messages.length === 0 && <li className="chat-empty">No messages yet.</li>}
                {messages.map((message) => (
                    <ChatMessage key={message.id} message={message} now={now} />
                ))}
            </ol>

            {!canEdit ? (
                <p className="chat-locked">View only. Ask an admin for edit access to post here.</p>
            ) : !hasConnectedMcp ? (
                <div className="chat-locked">
                    <span>Connect your agent to post in this room.</span>
                    <button type="button" className="btn btn-sm btn-primary" onClick={onConnect}>
                        Connect
                    </button>
                </div>
            ) : (
                <form className="chat-composer" onSubmit={submit}>
                    <label className="chat-target">
                        <span>To</span>
                        <select className="select" value={target} onChange={(event) => setTarget(event.target.value)}>
                            <option value="all">Everyone</option>
                            {participants.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.user_name} ({p.device_name})
                                </option>
                            ))}
                        </select>
                    </label>
                    <p className="chat-hint">{deliveryHint(targetAgent, participants, signedInEmail)}</p>
                    <textarea
                        className="chat-input"
                        rows={2}
                        value={text}
                        aria-label="Message"
                        placeholder={targetAgent ? `Prompt ${targetAgent.user_name} on ${targetAgent.device_name}…` : 'Message the room…'}
                        onChange={(event) => setText(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' && !event.shiftKey) submit(event);
                        }}
                    />
                    <button type="submit" className="btn btn-sm btn-primary" disabled={sending || !text.trim()}>
                        {sending ? 'Sending…' : 'Send'}
                    </button>
                </form>
            )}
        </div>
    );
}
