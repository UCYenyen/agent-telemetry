import { useEffect, useRef } from 'react';

export default function Modal({ open, onClose, className, children }) {
    const dialog = useRef(null);

    useEffect(() => {
        const element = dialog.current;

        if (open && !element.open) {
            element.showModal();
        } else if (!open && element.open) {
            element.close();
        }
    }, [open]);

    return (
        <dialog ref={dialog} className={className} onClose={onClose}>
            {open && children}
        </dialog>
    );
}
