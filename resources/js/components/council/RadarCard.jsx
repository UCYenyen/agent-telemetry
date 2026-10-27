export default function RadarCard({ icon, name, model, color, action, badge, text, thinking = false, done = !thinking, chairman = false }) {
    const classes = ['c-radar-card', thinking && 'thinking', done && 'done', chairman && 'chairman'].filter(Boolean).join(' ');

    return (
        <div className={classes} style={chairman ? undefined : { borderLeft: `3px solid ${color || '#00d2ff'}` }}>
            <div className="c-radar-head">
                <div className="c-radar-name">
                    <span>{icon}</span>
                    <span>{name}</span>
                </div>
                <span className={`c-radar-status-badge ${badge}`}>{text}</span>
            </div>
            <div className="c-radar-action">{action || 'Standing by…'}</div>
            <div className="c-radar-model">{model}</div>
        </div>
    );
}
