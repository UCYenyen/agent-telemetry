import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { AppProvider } from './context/AppContext';
import App from './components/App';

createRoot(document.getElementById('app')).render(
    <AppProvider>
        <App />
    </AppProvider>,
);
