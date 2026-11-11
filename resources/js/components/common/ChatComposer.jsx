import { useEffect, useRef, useState } from 'react';

const MAX_IMAGES = 6;

export default function ChatComposer({
    placeholder,
    hint,
    submitLabel,
    rows = 1,
    onSubmit,
    disabled = false,
    quickActions = [],
    leftSlot = null,
    allowImages = false,
}) {
    const [text, setText] = useState('');
    const [images, setImages] = useState([]);
    const textarea = useRef(null);
    const fileInput = useRef(null);

    const latestImages = useRef(images);
    latestImages.current = images;

    // Free the remaining preview URLs when the composer unmounts
    useEffect(() => () => latestImages.current.forEach((image) => URL.revokeObjectURL(image.preview)), []);

    const addImages = (files) => {
        const picked = Array.from(files).filter((file) => file.type.startsWith('image/'));
        if (!allowImages || disabled || !picked.length) return;
        setImages((current) =>
            [...current, ...picked.map((file) => ({ file, preview: URL.createObjectURL(file) }))].slice(0, MAX_IMAGES),
        );
    };

    const removeImage = (index) => {
        URL.revokeObjectURL(images[index].preview);
        setImages((current) => current.filter((_, i) => i !== index));
    };

    const handlePaste = (event) => {
        const files = Array.from(event.clipboardData?.files ?? []);
        if (allowImages && files.some((file) => file.type.startsWith('image/'))) {
            event.preventDefault();
            addImages(files);
        }
    };

    const handleDrop = (event) => {
        if (!allowImages) return;
        event.preventDefault();
        addImages(event.dataTransfer.files);
    };

    const resize = () => {
        if (!textarea.current) return;
        textarea.current.style.height = 'auto';
        textarea.current.style.height = `${Math.min(textarea.current.scrollHeight, 200)}px`;
    };

    const submit = (event) => {
        event?.preventDefault();
        if (disabled) return;
        const value = text.trim();

        if (!value && !images.length) {
            return;
        }

        const files = images.map((image) => image.file);
        images.forEach((image) => URL.revokeObjectURL(image.preview));
        setText('');
        setImages([]);
        if (textarea.current) {
            textarea.current.style.height = 'auto';
        }
        if (files.length) {
            onSubmit(value, files);
        } else {
            onSubmit(value);
        }
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
                                <span className="cc-pill-glyph">{item.icon || 'AI'}</span>
                                <span>{item.label}</span>
                            </button>
                        ))}
                    </div>
                )}
                <form onSubmit={submit} onDragOver={allowImages ? (event) => event.preventDefault() : undefined} onDrop={handleDrop}>
                    <div className={`ow-input-box cc-input-card${disabled ? ' is-disabled' : ''}`}>
                        {images.length > 0 && (
                            <div className="cc-attachments cc-attachments-draft">
                                {images.map((image, index) => (
                                    <div key={image.preview} className="cc-attachment">
                                        <img src={image.preview} alt={image.file.name} />
                                        <button
                                            type="button"
                                            className="cc-attachment-remove"
                                            onClick={() => removeImage(index)}
                                            aria-label={`Remove ${image.file.name}`}
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="cc-prompt-row">
                            <span className="cc-prompt-chevron" aria-hidden="true">&gt;</span>
                            <textarea
                                ref={textarea}
                                className="ow-textarea cc-textarea"
                                rows={rows}
                                placeholder={placeholder}
                                value={text}
                                onChange={(event) => setText(event.target.value)}
                                onInput={resize}
                                onKeyDown={handleKeyDown}
                                onPaste={handlePaste}
                                disabled={disabled}
                                required={!images.length}
                            />
                        </div>
                        <div className="ow-input-bar cc-input-bar">
                            <div className="ow-input-hint cc-input-meta">
                                <span className="cc-mode-badge"> auto mode</span>
                                {leftSlot}
                                {hint}
                            </div>
                            <div className="cc-submit-group">
                                {allowImages && (
                                    <>
                                        <input
                                            ref={fileInput}
                                            type="file"
                                            accept="image/png,image/jpeg,image/gif,image/webp"
                                            multiple
                                            hidden
                                            onChange={(event) => {
                                                addImages(event.target.files);
                                                event.target.value = '';
                                            }}
                                        />
                                        <button
                                            type="button"
                                            className="cc-quick-pill"
                                            onClick={() => fileInput.current?.click()}
                                            disabled={disabled || images.length >= MAX_IMAGES}
                                            title="Attach images (or paste / drop them)"
                                        >
                                            + IMAGE
                                        </button>
                                    </>
                                )}
                                <span className="cc-kbd-hint"> to send · ⇧ newline</span>
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
