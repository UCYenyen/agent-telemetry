export default function StagePill({ state, title, status, percent, caption }) {
    return (
        <div className={`c-step-pill ${state}`}>
            <div className="c-step-head">
                <span>{title}</span>
                <span>{status}</span>
            </div>
            <div className="c-step-bar-wrap">
                <div className="c-step-bar" style={{ width: `${percent}%` }} />
            </div>
            <div className="c-step-sub">{caption}</div>
        </div>
    );
}
