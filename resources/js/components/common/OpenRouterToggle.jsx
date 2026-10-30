export default function OpenRouterToggle({ on, onToggle, title = 'Toggle OpenRouter API' }) {
    return (
        <button type="button" className={`badge ${on ? 'or-on' : 'fb-off'}`} onClick={() => onToggle(!on)} title={title} aria-pressed={on}>
            OPENROUTER: {on ? 'ON' : 'OFF'}
        </button>
    );
}
