/*
 * Central API helpers used by the frontend to call server endpoints.
 *
 * Exposes a global `AppAPI` object with convenience methods for JSON
 * POSTs and form uploads. The object is intentionally small and synchronous
 * in shape (returns Promises) to keep demo code straightforward.
 *
 * Usage examples:
 *  - `AppAPI.postJSON('/api/endpoint', { action: 'list' })`
 *  - `AppAPI.uploadForm(document.getElementById('upload-form'))`
 */
(function(window){
  const AppAPI = {
    endpoints: {},
    init(endpoints){ this.endpoints = endpoints || {}; },
    postJSON: async function(url, payload){
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const raw = await res.text();
      try {
        return JSON.parse(raw || '{}');
      } catch (_error) {
        return {
          success: false,
          ok: false,
          status: res.status,
          error: (raw || '').trim() || 'Server returned a non-JSON response.',
        };
      }
    },
    postFormData: async function(url, payload){
      const fd = new FormData();
      Object.entries(payload || {}).forEach(([key, value]) => {
        if (value === undefined || value === null) return;
        if (value instanceof File) {
          if (value.size > 0) fd.append(key, value);
          return;
        }
        fd.append(key, String(value));
      });

      const res = await fetch(url, {
        method: 'POST',
        body: fd,
      });
      const raw = await res.text();
      try {
        return JSON.parse(raw || '{}');
      } catch (_error) {
        return {
          success: false,
          ok: false,
          status: res.status,
          error: (raw || '').trim() || 'Server returned a non-JSON response.',
        };
      }
    },
    uploadForm: async function(form){
      const fd = new FormData(form);
      const res = await fetch(form.action, { method: 'POST', body: fd });
      try { return await res.json(); } catch (e) { return res; }
    },
    companies: {
      list(url){ return AppAPI.postJSON(url, { action: 'list' }); },
      create(url, payload){
        const body = Object.assign({ action: 'create' }, payload || {});
        return (body.logo_file instanceof File) ? AppAPI.postFormData(url, body) : AppAPI.postJSON(url, body);
      },
      update(url, payload){
        const body = Object.assign({ action: 'update' }, payload || {});
        return (body.logo_file instanceof File) ? AppAPI.postFormData(url, body) : AppAPI.postJSON(url, body);
      },
      setSignatureIp(url, companyId, ip){ return AppAPI.postJSON(url, { action: 'set_signature_ip', company_id: companyId, ip }); },
      delete(url, id){ return AppAPI.postJSON(url, { action: 'delete', id }); }
    },
    departments: {
      list(url, companyId){ return AppAPI.postJSON(url, { action: 'list', company_id: companyId }); },
      delete(url, id){ return AppAPI.postJSON(url, { action: 'delete', id }); },
      create(url, companyId, name){ return AppAPI.postJSON(url, { action: 'create', company_id: companyId, name }); },
      update(url, payload){ return AppAPI.postJSON(url, Object.assign({ action: 'update' }, payload)); }
    },
    users: {
      listByCompany(url, companyId){ return AppAPI.postJSON(url, { action: 'list_by_company', company_id: companyId }); },
      delete(url, id){ return AppAPI.postJSON(url, { action: 'delete', id }); },
      create(url, payload){ return AppAPI.postJSON(url, Object.assign({ action: 'create' }, payload)); },
      update(url, payload){ return AppAPI.postJSON(url, Object.assign({ action: 'update' }, payload)); }
    }
    ,
    shifts: {
      list(url, departmentId){ return AppAPI.postJSON(url, { action: 'list', department_id: departmentId }); },
      create(url, payload){ return AppAPI.postJSON(url, Object.assign({ action: 'create' }, payload)); },
      update(url, payload){ return AppAPI.postJSON(url, Object.assign({ action: 'update' }, payload)); },
      delete(url, id){ return AppAPI.postJSON(url, { action: 'delete', id }); }
    },
    commercial: {
      list(url){ return AppAPI.postJSON(url, { action: 'list' }); },
      update(url, videos){ return AppAPI.postJSON(url, { action: 'update', videos }); }
    }
  };
  window.AppAPI = AppAPI;
})(window);
