'use client';

import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import { useAuthStore } from '@/stores/auth';
import type { ApiErrorBody, RefreshResponse } from '@/types/api';

/**
 * Cliente HTTP unificado.
 *
 * Garantías:
 *  - `withCredentials: true` para que viajen las cookies HttpOnly (refresh, csrf)
 *  - Interceptor de request: pega el `Authorization: Bearer <access>` desde el store
 *  - Interceptor de response:
 *      * 401 -> intenta /auth/refresh una sola vez; si OK reintenta la request original;
 *               si falla, limpia store y dispara evento 'auth:unauthorized' para que la UI redirija
 *      * 419 / CSRF_INVALID -> mismo flujo (refresh + retry)
 *      * adjunta el header X-CSRF-Token si está en el store
 */
const baseURL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8080/api/v1';

/**
 * Nombre de la cookie csrf seteada por el backend (no-HttpOnly por diseño,
 * double-submit pattern). Si cambia, actualizar también `api/config/settings.php`.
 */
const CSRF_COOKIE_NAME = 'ithub_csrf';

/** Lee una cookie por nombre. Devuelve null si no existe o si estamos en SSR. */
function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const prefix = name + '=';
  for (const part of document.cookie.split(';')) {
    const trimmed = part.trim();
    if (trimmed.startsWith(prefix)) {
      return decodeURIComponent(trimmed.slice(prefix.length));
    }
  }
  return null;
}

/**
 * Devuelve el CSRF token vigente: primero del store (en memoria), sino
 * de la cookie no-HttpOnly. Esto permite que el flujo de hydrate funcione
 * después de un reload, cuando el store está vacío pero la cookie persiste.
 */
function getCsrfToken(): string | null {
  return useAuthStore.getState().csrfToken ?? readCookie(CSRF_COOKIE_NAME);
}

export const api = axios.create({
  baseURL,
  withCredentials: true,
  timeout: 20_000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

// ----------- Request interceptor -----------
api.interceptors.request.use((config) => {
  const { accessToken } = useAuthStore.getState();
  if (accessToken) {
    config.headers.set('Authorization', `Bearer ${accessToken}`);
  }
  const csrf = getCsrfToken();
  if (csrf) {
    config.headers.set('X-CSRF-Token', csrf);
  }
  return config;
});

// ----------- Response interceptor -----------
let refreshInFlight: Promise<string | null> | null = null;

async function tryRefresh(): Promise<string | null> {
  if (refreshInFlight) return refreshInFlight;

  refreshInFlight = (async () => {
    try {
      const csrf = getCsrfToken();
      const headers: Record<string, string> = {};
      if (csrf) headers['X-CSRF-Token'] = csrf;

      const resp = await axios.post<{ data: RefreshResponse }>(
        `${baseURL}/auth/refresh`,
        {},
        { withCredentials: true, headers },
      );
      const data = resp.data.data;
      useAuthStore.getState().setSession({
        user: data.user,
        accessToken: data.access_token,
        accessExpiresAt: data.access_expires_at,
      });
      return data.access_token;
    } catch {
      useAuthStore.getState().clear();
      // Avisamos a la UI (route guards) que perdimos sesión
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('auth:unauthorized'));
      }
      return null;
    } finally {
      refreshInFlight = null;
    }
  })();

  return refreshInFlight;
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiErrorBody>) => {
    const original = error.config as (AxiosRequestConfig & { _retried?: boolean }) | undefined;
    const status = error.response?.status;
    const errorCode = error.response?.data?.error?.code;
    const isAuthEndpoint =
      original?.url?.includes('/auth/login') ||
      original?.url?.includes('/auth/refresh') ||
      original?.url?.includes('/auth/logout');

    // Retry una sola vez con refresh si el access expiró
    if (
      original &&
      !original._retried &&
      !isAuthEndpoint &&
      (status === 401 || (status === 419 && errorCode === 'CSRF_INVALID'))
    ) {
      const newAccess = await tryRefresh();
      if (newAccess) {
        original._retried = true;
        original.headers = { ...(original.headers ?? {}), Authorization: `Bearer ${newAccess}` };
        return api.request(original);
      }
    }
    return Promise.reject(error);
  },
);

/**
 * Convierte el error de Axios al shape de error de la API (o un mensaje genérico).
 * Si el backend devolvió `details` (errores de validación por campo), los
 * anexa al mensaje para que el usuario sepa exactamente qué corregir.
 */
export function apiErrorMessage(err: unknown, fallback = 'Ocurrió un error'): string {
  if (axios.isAxiosError<ApiErrorBody>(err)) {
    const apiError = err.response?.data?.error;
    const base = apiError?.message ?? err.message ?? fallback;

    const details = apiError?.details;
    if (details && typeof details === 'object') {
      const lines = Object.entries(details)
        .filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([campo, v]) => `${campo}: ${Array.isArray(v) ? v.join(', ') : String(v)}`);
      if (lines.length > 0) {
        return `${base} — ${lines.join(' · ')}`;
      }
    }
    return base;
  }
  return fallback;
}
