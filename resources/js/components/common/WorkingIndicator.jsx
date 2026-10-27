export default function WorkingIndicator({ label }) {
    return (
        <div className="ow-working">
            <div className="ow-dots">
                <span />
                <span />
                <span />
            </div>
            <span>{label}</span>
        </div>
    );
}
