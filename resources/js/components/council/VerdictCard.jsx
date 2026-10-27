export default function VerdictCard({ message }) {
    return (
        <div className="c-verdict-card">
            <div className="c-verdict-head">
                <div className="c-verdict-title">⚖️ Council Verdict</div>
                <span className="c-take-model">
                    CHAIRMAN MODEL: {message.model} · {message.elapsed_sec ?? ''}s
                </span>
            </div>
            <div className="c-verdict-body">{message.content}</div>
        </div>
    );
}
