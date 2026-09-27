import api from './api';

export const dashboardSummary = params => api.get('/dashboard', { params });
