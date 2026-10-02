import { useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { api } from './services/api';
import Sidebar from './components/Sidebar';
import TopBar from './components/TopBar';
import Landing from './pages/Landing';
import Login from './pages/Login';
import Register from './pages/Register';
import Dashboard from './pages/Dashboard';
import Editor from './pages/Editor';
import EditorV2 from './pages/EditorV2';
import './index.css';

// Protected route wrapper
function ProtectedRoute({ children }) {
    if (!api.isAuthenticated()) {
        return <Navigate to="/login" replace />;
    }
    return children;
}

// Public-only route
function PublicRoute({ children }) {
    if (api.isAuthenticated()) {
        return <Navigate to="/dashboard" replace />;
    }
    return children;
}

// Layout with sidebar for authenticated pages
function AppShell({ children }) {
    return (
        <div className="app-shell">
            <Sidebar />
            <div className="main-area">
                <TopBar />
                {children}
            </div>
        </div>
    );
}

// Layout wrapper that hides sidebar on editor (editor has its own full layout)
function AppShellEditor({ children }) {
    return (
        <div className="app-shell">
            <Sidebar />
            <div className="main-area">
                {children}
            </div>
        </div>
    );
}

function App() {
    const [ready, setReady] = useState(false);
    useEffect(() => { let active = true; api.bootstrap().finally(() => { if (active) setReady(true); }); return () => { active = false; }; }, []);
    if (!ready) return <div className="auth-page">Loading…</div>;
    return (
        <BrowserRouter>
            <Routes>
                {/* Public (no sidebar) */}
                <Route path="/" element={<Landing />} />
                <Route path="/login" element={<PublicRoute><Login /></PublicRoute>} />
                <Route path="/register" element={<PublicRoute>{api.usesSharedSignIn() ? <Login /> : <Register />}</PublicRoute>} />

                {/* Protected with sidebar + topbar */}
                <Route path="/dashboard" element={
                    <ProtectedRoute>
                        <AppShell><Dashboard /></AppShell>
                    </ProtectedRoute>
                } />

                {/* Editor V1 — sidebar but no topbar */}
                <Route path="/editor/:projectId" element={
                    <ProtectedRoute>
                        <AppShellEditor><Editor /></AppShellEditor>
                    </ProtectedRoute>
                } />

                {/* Editor V2 — full screen, no sidebar (FreeCut-inspired) */}
                <Route path="/editor-v2/:projectId" element={
                    <ProtectedRoute>
                        <EditorV2 />
                    </ProtectedRoute>
                } />

                {/* Fallback */}
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </BrowserRouter>
    );
}

export default App;
