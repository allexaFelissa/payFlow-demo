import api from './api';

export const employees = params => api.get('/employees', { params });
export const employeeDivisions = () => api.get('/employees/divisions');
export const employeeLoanHistory = employeeId => api.get(`/employees/${employeeId}/loan-history`);
export const clearEmployeeLoan = employeeId => api.post(`/employees/${employeeId}/loan/clear`);
export const saveEmployee = data => data.id ? api.put(`/employees/${data.id}`, data) : api.post('/employees', data);
export const removeEmployee = id => api.delete(`/employees/${id}`);
