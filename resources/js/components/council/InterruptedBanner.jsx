export default function InterruptedBanner({ question, onAsk }) {
    return (
        <div className="c-interrupted-banner">
            <div className="c-interrupted-text">
                [WARN] <strong>Deliberation Interrupted:</strong> Deliberation was interrupted by a server restart or process stop.
            </div>
            {question && (
                <button type="button" className="btn-sm" onClick={() => onAsk(question)}>
                    ↻ RE-CONVENE COUNCIL
                </button>
            )}
        </div>
    );
}
