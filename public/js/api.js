/** Appels à l'API : JSON par défaut, FormData pour les envois de fichiers. */
export class ApiError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (handler) => { onUnauthorized = handler; };

export async function api(path, { method = 'GET', body, raw = false } = {}) {
  const headers = { 'X-Requested-With': 'afparea' };
  let payload = body;
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch(`/api${path}`, { method, headers, body: payload, credentials: 'same-origin' });
  } catch {
    throw new ApiError(0, 'Connexion au serveur impossible. Vérifiez votre réseau.');
  }
  if (raw && response.ok) return response;
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401 && path !== '/login') onUnauthorized();
    throw new ApiError(response.status, data.error || 'Une erreur est survenue.');
  }
  return data;
}

export function form(fields, files = [], fileField = 'files') {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== undefined) data.append(key, value === null ? '' : value);
  }
  for (const file of files) data.append(fileField, file);
  return data;
}
