import api from './api';

export const overtimeRows = params => api.get('/overtime', { params });
export const importOvertime = data => api.post('/overtime/import', data, { headers: { 'Content-Type': 'multipart/form-data' } });
export const saveOvertime = data => api.post('/overtime', data);
export const updateOvertime = (id, data) => api.put(`/overtime/${id}`, data);
export const deleteOvertime = id => api.delete(`/overtime/${id}`);
export const overtimeEmployeeDetails = (periodId, employeeId) => api.get(`/overtime/${periodId}/employees/${employeeId}`);
export const saveOvertimeEmployeeDetails = (periodId, employeeId, rows) => api.put(`/overtime/${periodId}/employees/${employeeId}`, { rows });
export const extraTimeEmployeeDetails = (periodId, employeeId) => api.get(`/overtime/${periodId}/employees/${employeeId}/extra-time`);
export const saveExtraTimeEmployeeDetails = (periodId, employeeId, rows) => api.put(`/overtime/${periodId}/employees/${employeeId}/extra-time`, { rows });
