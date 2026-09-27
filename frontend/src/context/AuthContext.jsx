import { createContext, useContext, useEffect, useState } from 'react';
import api from '../services/api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!localStorage.getItem('payflow_token')) { setLoading(false); return; }
    api.get('/me').then(response => setUser(response.data.data)).catch(() => localStorage.removeItem('payflow_token')).finally(() => setLoading(false));
  }, []);

  const login = async credentials => {
    const response = await api.post('/login', credentials);
    localStorage.setItem('payflow_token', response.data.token);
    setUser(response.data.user);
  };
  const logout = async () => {
    try { await api.post('/logout'); } finally { localStorage.removeItem('payflow_token'); setUser(null); }
  };

  return <AuthContext.Provider value={{ user, loading, login, logout, isAdmin: user?.role === 'admin' }}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
