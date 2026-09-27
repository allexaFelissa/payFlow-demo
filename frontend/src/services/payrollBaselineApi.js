import api from './api';

export const employeeBaselines = params => api.get('/payroll-baselines/employees', { params });
export const saveEmployeeBaseline = (employeeId, data) => api.put(`/payroll-baselines/employees/${employeeId}`, data);
export const payrollBaselineConfiguration = () => api.get('/payroll-baselines/configuration');
export const savePayrollBaselineConfiguration = data => api.put('/payroll-baselines/configuration', data);
