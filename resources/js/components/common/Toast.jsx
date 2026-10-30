export default function Toast({ message, tone = 'info' }) {
    const isError = tone === 'error';

    return (
        <div
            className={`toast${message ? ' show' : ''}${isError ? ' error' : ''}`}
            role={isError ? 'alert' : 'status'}
            aria-live={isError ? 'assertive' : 'polite'}
        >
            {message}
        </div>
    );
}
