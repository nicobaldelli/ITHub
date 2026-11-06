'use client';

import { useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { api, apiErrorMessage, tryRefresh } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import type { ApiSuccess, LoginResponse, User } from '@/types/api';

export function useAuth() {
  const router = useRouter();
  const { user, accessToken, hydrated, setSession, clear, setHydrated, updateUser } = useAuthStore();

  /** Login con el ID token que devuelve el botón de Google. Es el único login. */
  const loginGoogle = useCallback(
    async (credential: string) => {
      try {
        const resp = await api.post<ApiSuccess<LoginResponse>>('/auth/google', { credential });
        const data = resp.data.data;
        setSession({
          user: data.user,
          accessToken: data.access_token,
          accessExpiresAt: data.access_expires_at,
          csrfToken: data.csrf_token,
        });
        return data;
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'No se pudo iniciar sesión con Google'));
      }
    },
    [setSession],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // ignoramos: igual limpiamos local
    } finally {
      clear();
      router.push('/login');
    }
  }, [clear, router]);

  /**
   * Intenta recuperar sesión leyendo la cookie de refresh.
   * Usa `tryRefresh` (single-flight) y NO un post directo: si a la vez un hook
   * de datos recibe 401, comparte esta misma promesa en vez de disparar un
   * segundo refresh que el backend trataría como reuso del token.
   */
  const hydrate = useCallback(async () => {
    try {
      const token = await tryRefresh();
      if (!token) return null;
      return useAuthStore.getState().user;
    } finally {
      setHydrated(true);
    }
  }, [setHydrated]);

  const refreshMe = useCallback(async () => {
    const resp = await api.get<ApiSuccess<User>>('/auth/me');
    updateUser(resp.data.data);
    return resp.data.data;
  }, [updateUser]);

  return {
    user,
    accessToken,
    hydrated,
    isAuthenticated: !!accessToken && !!user,
    loginGoogle,
    logout,
    hydrate,
    refreshMe,
  };
}
