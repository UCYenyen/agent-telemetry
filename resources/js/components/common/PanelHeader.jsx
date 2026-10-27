export default function PanelHeader({ index, title, children }) {
    return (
        <div className="panel-header">
            <div className="section-tag">
                <span className="section-idx">{index}</span>
                <h2 className="section-title">{title}</h2>
            </div>
            {children}
        </div>
    );
}
