export default function EmptyState({ glyph, title, description, children }) {
    return (
        <div className="ow-empty-state">
            <div className="ow-empty-glyph">{glyph}</div>
            <div className="ow-empty-title">{title}</div>
            <div className="ow-empty-desc">{description}</div>
            {children}
        </div>
    );
}
