import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../services/api';

export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const navigate = useNavigate();

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);
        const { ok, data } = await api.login(email, password);
        if (ok) {
            navigate('/dashboard');
        } else {
            setError(data.message || data.errors?.email?.[0] || 'Giriş uğursuz oldu');
        }
        setLoading(false);
    };

    if (api.usesSharedSignIn()) return <div className="auth-page"><div className="auth-card"><h1>Giriş</h1><a className="btn-primary btn-full" href="/api/auth/sso/login">Tural hesabı ilə daxil ol</a></div></div>;

    return (
        <div className="auth-page">
            <div className="auth-card">
                <h1>Giriş</h1>
                <p className="subtitle">Hesabınıza daxil olun</p>

                {error && <div className="error">{error}</div>}

                <form onSubmit={handleSubmit}>
                    <div className="form-group">
                        <label>Email</label>
                        <input type="email" className="form-input" value={email} onChange={e => setEmail(e.target.value)} placeholder="email@example.com" required />
                    </div>
                    <div className="form-group">
                        <label>Şifrə</label>
                        <input type="password" className="form-input" value={password} onChange={e => setPassword(e.target.value)} placeholder="••••••••" required />
                    </div>
                    <button type="submit" className="btn-primary btn-full" disabled={loading}>
                        {loading ? 'Giriş edilir...' : 'Daxil Ol →'}
                    </button>
                </form>

                <p className="auth-footer">
                    Hesabınız yoxdur? <Link to="/register">Qeydiyyat</Link>
                </p>
            </div>
        </div>
    );
}
