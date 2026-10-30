export default function TakeCard({ message }) {
    const isReview = message.type === 'peer_review';
    const border = isReview ? `4px dashed ${message.color || '#ffb300'}` : `4px solid ${message.color || '#00d2ff'}`;

    return (
        <div className="c-take-card" style={{ borderLeft: border }}>
            <div className="c-take-head">
                <div className={`c-take-sender${isReview ? ' small' : ''}`}>
                    <span>
                        {isReview && '[REVIEW] '}
                        {message.icon || '[MEM]'}
                    </span>
                    <span>{message.sender}</span>
                </div>
                <div className="c-take-meta">
                    <span className="c-take-model">{message.model}</span>
                    {message.elapsed_sec !== null && <span className="c-take-model">{message.elapsed_sec}s</span>}
                </div>
            </div>
            <div className="c-take-body">{message.content}</div>
        </div>
    );
}
