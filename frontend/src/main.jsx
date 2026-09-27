import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import AppLayout from './components/Layout/AppLayout';
import { AuthProvider, useAuth } from './context/AuthContext';
import Login from './pages/Login/Index';
import Dashboard from './pages/Dashboard/Index';
import Employees from './pages/Employees/Index';
import Attendance from './pages/Attendance/Index';
import PayrollBaseline from './pages/PayrollBaseline/Index';
import PayrollCalculation from './pages/PayrollCalculation/Index';
import Overtime from './pages/Overtime/Index';
import Loading from './components/UI/Loading';
import './index.css';

function Protected({ children, admin = false }) {
  const { user, loading, isAdmin } = useAuth();
  const location = useLocation();
  if (loading) return <Loading fullScreen label="Menyiapkan ruang kerja…"/>;
  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace/>;
  if (admin && !isAdmin) return <Navigate to="/" replace/>;
  return <AppLayout>{children}</AppLayout>;
}

function App() {
  return <Routes>
    <Route path="/login" element={<Login/>}/>
    <Route path="/" element={<Protected><Dashboard/></Protected>}/>
    <Route path="/employees" element={<Protected><Employees/></Protected>}/>
    <Route path="/attendance" element={<Protected><Attendance/></Protected>}/>
    <Route path="/overtime" element={<Protected><Overtime/></Protected>}/>
    <Route path="/payroll" element={<Protected admin><Navigate to="/payroll/baseline" replace/></Protected>}/>
    <Route path="/payroll/baseline" element={<Protected admin><PayrollBaseline/></Protected>}/>
    <Route path="/payroll/calculation" element={<Protected admin><PayrollCalculation/></Protected>}/>
    <Route path="*" element={<Navigate to="/" replace/>}/>
  </Routes>;
}

createRoot(document.getElementById('root')).render(
  <StrictMode><BrowserRouter><AuthProvider><App/></AuthProvider></BrowserRouter></StrictMode>,
);
