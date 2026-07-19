import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function AppHeader() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate('/login', { replace: true });
  }

  return (
    <header
      className="border-b px-4 py-3 flex items-center justify-between"
      style={{ borderColor: 'var(--color-line)', backgroundColor: 'var(--color-paper-raised)' }}
    >
      <Link to="/goals" className="text-lg" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-ink)' }}>
        HorizonPlan
      </Link>
      <div className="flex items-center gap-4 text-sm text-[var(--color-ink-soft)]">
        {user && <span className="capitalize">{user.role}</span>}
        <button onClick={handleLogout} className="hover:text-[var(--color-ink)] transition-colors">
          Sign out
        </button>
      </div>
    </header>
  );
}
