import api from './api';

export const attendance = params => api.get('/attendance', { params });
export const generate = data => api.post('/attendance/generate', data);
export const importAttendance = data => api.post('/attendance/import', data, { headers: { 'Content-Type': 'multipart/form-data' } });
export const previewAttendanceImport = data => api.post('/attendance/import-preview', data, { headers: { 'Content-Type': 'multipart/form-data' } });
export const editCell = data => api.patch('/attendance/cell', data);
export const overtime = data => api.patch('/attendance/overtime', data);
export const lock = data => api.post('/attendance/lock', data);
export const exportAttendance = params => api.get('/attendance/export', { params, responseType: 'blob' });
