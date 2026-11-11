/**
 * Appels à l'API : JSON par défaut, FormData pour les envois de fichiers.
 * Toutes les adresses sont relatives : la plateforme fonctionne à la racine d'un domaine comme dans un sous-dossier.
 */
export class ApiError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (handler) => { onUnauthorized = handler; };

/** Adresse d'une route de l'API, par exemple apiUrl('/courses/3/import?preview'). */
export function apiUrl(path) {
  const [route, query] = path.split('?');
  return `api/index.php?r=${encodeURIComponent(route)}${query ? `&${query}` : ''}`;
}

export const fileUrl = (id) => apiUrl(`/files/${id}`);

export async function api(path, { method = 'GET', body, raw = false } = {}) {
  const headers = { 'X-Requested-With': 'afparea' };
  let payload = body;
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  // Certains hébergements filtrent PATCH, PUT et DELETE : ils sont transmis en POST avec un en-tête.
  let sent = method;
  if (['PATCH', 'PUT', 'DELETE'].includes(method)) {
    headers['X-HTTP-Method-Override'] = method;
    sent = 'POST';
  }
  let response;
  try {
    response = await fetch(apiUrl(path), { method: sent, headers, body: payload, credentials: 'same-origin' });
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

/** Formulaire avec fichiers. Le champ « files » accepte plusieurs fichiers ; les autres n'en portent qu'un. */
export function form(fields, files = [], fileField = 'files') {
  const data = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== undefined) data.append(key, value === null ? '' : value);
  }
  for (const file of files) data.append(fileField === 'files' ? 'files[]' : fileField, file);
  return data;
}
