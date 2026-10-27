import { useRef, useState } from 'react';

export default function ChatComposer({ placeholder, hint, submitLabel, rows = 1, onSubmit }) {
    const [text, setText] = useState('');
    const textarea = useRef(null);

    const resize = () => {
        textarea.current.style.height = 'auto';
        textarea.current.style.height = `${Math.min(textarea.current.scrollHeight, 160)}px`;
    };

    const submit = (event) => {
        event?.preventDefault();
        const value = text.trim();

        if (!value) {
            return;
        }

        setText('');
        textarea.current.style.height = 'auto';
        onSubmit(value);
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            submit(event);
        }
    };

    return (
        <div className="ow-footer">
            <form onSubmit={submit}>
                <div className="ow-input-box">
                    <textarea
                        ref={textarea}
                        className="ow-textarea"
                        rows={rows}
                        placeholder={placeholder}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        onInput={resize}
                        onKeyDown={handleKeyDown}
                        required
                    />
                    <div className="ow-input-bar">
                        <div className="ow-input-hint">{hint}</div>
                        <button type="submit">{submitLabel}</button>
                    </div>
                </div>
            </form>
        </div>
    );
}
