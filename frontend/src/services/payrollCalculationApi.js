import api from './api';

export const calculatePayroll = (payrollPeriodId, filters = {}) => api.post('/payroll-calculations/calculate', { payroll_period_id: payrollPeriodId, ...filters });
export const payrollDrafts = payrollPeriodId => api.get(`/payroll-calculations/${payrollPeriodId}`);
export const downloadPph21Data = (periodId, pt) => api.get(`/payroll-calculations/${periodId}/pph21-data/download`, { params: { pt }, responseType: 'blob' });
export const payrollCompletionStatus = (periodId, pt) => api.get(`/payroll-completions/${periodId}`, { params: { pt } });
export const completePayroll = data => api.post('/payroll-completions/complete', data);
export const undoPayrollCompletion = data => api.post('/payroll-completions/undo', data);
export const downloadPayrollSalarySlips = (periodId, filters = {}) => api.get(`/payroll-salary-slips/${periodId}`, { params: filters, responseType: 'blob' });
export const payrollRecapSummary = (periodId, filters = {}) => api.get(`/payroll-recaps/${periodId}/summary`, { params: filters });
export const downloadPayrollRecap = (periodId, filters = {}) => api.get(`/payroll-recaps/${periodId}`, { params: filters, responseType: 'blob' });
export const createManualAdjustment = (payrollCalculationId, data) => api.post(`/payroll-calculations/${payrollCalculationId}/manual-adjustments`, data);
export const updateManualAdjustment = (payrollCalculationId, adjustmentId, data) => api.put(`/payroll-calculations/${payrollCalculationId}/manual-adjustments/${adjustmentId}`, data);
export const deleteManualAdjustment = (payrollCalculationId, adjustmentId) => api.delete(`/payroll-calculations/${payrollCalculationId}/manual-adjustments/${adjustmentId}`);
