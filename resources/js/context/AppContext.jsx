import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { useInterval } from '../hooks/useInterval';
import Toast from '../components/common/Toast';

const AppContext = createContext(null);

export function AppProvider({ children }) {
    const [state, setState] = useState(null);
    const [syncedAt, setSyncedAt] = useState(null);
    const [message, setMessage] = useState(null);
    const [tone, setTone] = useState('info');
    const toastTimer = useRef(null);

    const searchParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
    const sharedToken = searchParams?.get('invite') || searchParams?.get('room') || null;

    const reload = useCallback(async () => {
        try {
            setState(await api.get('/api/state'));
            setSyncedAt(new Date());
        } catch {
            setSyncedAt(false);
        }
    }, []);

    const toast = useCallback((text, nextTone = 'info') => {
        clearTimeout(toastTimer.current);
        setMessage(text);
        setTone(nextTone);
        toastTimer.current = setTimeout(() => setMessage(null), nextTone === 'error' ? 7000 : 3500);
    }, []);

    const run = useCallback(async (action, success) => {
        try {
            const result = await action();
            if (success) {
                toast(typeof success === 'function' ? success(result) : success);
            }
            await reload();
            return result;
        } catch (error) {
            toast(error.message, 'error');
            return null;
        }
    }, [reload, toast]);

    useEffect(() => {
        reload();
    }, [reload]);

    useInterval(reload, 4000);

    return (
        <AppContext.Provider value={{ state, syncedAt, reload, toast, run, sharedToken }}>
            {children}
            <Toast message={message} tone={tone} />
        </AppContext.Provider>
    );
}

export function useApp() {
    return useContext(AppContext);
}
