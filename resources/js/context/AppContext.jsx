import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { useInterval } from '../hooks/useInterval';
import Toast from '../components/common/Toast';

const AppContext = createContext(null);

export function AppProvider({ children }) {
    const [state, setState] = useState(null);
    const [syncedAt, setSyncedAt] = useState(null);
    const [message, setMessage] = useState(null);
    const toastTimer = useRef(null);

    const reload = useCallback(async () => {
        try {
            setState(await api.get('/api/state'));
            setSyncedAt(new Date());
        } catch {
            setSyncedAt(false);
        }
    }, []);

    const toast = useCallback((text) => {
        clearTimeout(toastTimer.current);
        setMessage(text);
        toastTimer.current = setTimeout(() => setMessage(null), 3500);
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
            toast(error.message);
            return null;
        }
    }, [reload, toast]);

    useEffect(() => {
        reload();
    }, [reload]);

    useInterval(reload, 4000);

    return (
        <AppContext.Provider value={{ state, syncedAt, reload, toast, run }}>
            {children}
            <Toast message={message} />
        </AppContext.Provider>
    );
}

export function useApp() {
    return useContext(AppContext);
}
