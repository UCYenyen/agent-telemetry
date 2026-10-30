import { useRef, useState } from 'react';

export default function ChatComposer({
    placeholder,
    hint,
    submitLabel,
    rows = 1,
    onSubmit,
    disabled = false,
    quickActions = [],
    leftSlot = null,
}) {
    const [text, setText] = useState('');
    const textarea = useRef(null);

    const resize = () => {
        if (!textarea.current) return;
        textarea.current.style.height = 'auto';
        textarea.current.style.height = `${Math.min(textarea.current.scrollHeight, 200)}px`;
    };

    const submit = (event) => {
        event?.preventDefault();
        if (disabled) return;
        const value = text.trim();

        if (!value) {
            return;
        }

        setText('');
        if (textarea.current) {
            textarea.current.style.height = 'auto';
        }
        onSubmit(value);
    };

    const insertChip = (snippet) => {
        if (disabled) return;
        const next = text ? `${text} ${snippet}` : snippet;
        setText(next);
        setTimeout(() => {
            textarea.current?.focus();
            resize();
        }, 0);
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            submit(event);
        }
    };

    return (
        <div className="ow-footer cc-composer-footer">
            <div className="cc-composer-wrap">
                {quickActions.length > 0 && (
                    <div className="cc-quick-pills">
                        {quickActions.map((item) => (
                            <button
                                key={item.label}
                                type="button"
                                className="cc-quick-pill"
                                onClick={() => (item.instant ? onSubmit(item.value) : insertChip(item.value))}
                                disabled={disabled}
                            >
                                <span className="cc-pill-glyph">{item.icon || '✦'}</span>
                                <span>{item.label}</span>
                            </button>
                        ))}
                    </div>
                )}
                <form onSubmit={submit}>
                    <div className={`ow-input-box cc-input-card${disabled ? ' is-disabled' : ''}`}>
                        <div className="cc-prompt-row">
                            <span className="cc-prompt-chevron" aria-hidden="true">❯</span>
                            <textarea
                                ref={textarea}
                                className="ow-textarea cc-textarea"
                                rows={rows}
                                placeholder={placeholder}
                                value={text}
                                onChange={(event) => setText(event.target.value)}
                                onInput={resize}
                                onKeyDown={handleKeyDown}
                                disabled={disabled}
                                required
                            />
                        </div>
                        <div className="ow-input-bar cc-input-bar">
                            <div className="ow-input-hint cc-input-meta">
                                <span className="cc-mode-badge">⏵⏵ auto mode</span>
                                {leftSlot}
                                {hint}
                            </div>
                            <div className="cc-submit-group">
                                <span className="cc-kbd-hint">⏎ to send · ⇧⏎ newline</span>
                                <button type="submit" className="cc-send-btn" disabled={disabled}>
                                    {submitLabel}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
