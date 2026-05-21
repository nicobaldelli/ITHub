'use client';

import { useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { toast } from 'sonner';
import { api, apiErrorMessage } from '@/lib/api';
import { useAuthStore } from '@/stores/auth';
import type { ApiSuccess, LoginResponse, RefreshResponse, User } from '@/types/api';

export function useAuth() {
  const router = useRouter();
  const { user, accessToken, hydrated, setSession, clear, setHydrated, updateUser } = useAuthStore();

  const login = useCallback(
    async (email: string, password: string) => {
      try {
        const resp = await api.post<ApiSuccess<LoginResponse>>('/auth/login', { email, password });
        const data = resp.data.data;
        setSession({
          user: data.user,
          accessToken: data.access_token,
          accessExpiresAt: data.access_expires_at,
          csrfToken: data.csrf_token,
        });
        return data;
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'No se pudo iniciar sesión'));
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

  /** Intenta recuperar sesión leyendo la cookie de refresh. */
  const hydrate = useCallback(async () => {
    try {
      const resp = await api.post<ApiSuccess<RefreshResponse>>('/auth/refresh');
      const data = resp.data.data;
      setSession({
        user: data.user,
        accessToken: data.access_token,
        accessExpiresAt: data.access_expires_at,
      });
      return data.user;
    } catch {
      clear();
      return null;
    } finally {
      setHydrated(true);
    }
  }, [setSession, clear, setHydrated]);

  const changePassword = useCallback(
    async (newPassword: string, currentPassword?: string) => {
      try {
        await api.post('/auth/change-password', {
          new_password: newPassword,
          current_password: currentPassword,
        });
        toast.success('Password actualizado. Volvé a iniciar sesión.');
        clear();
        router.push('/login');
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'No se pudo cambiar la password'));
      }
    },
    [clear, router],
  );

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
    login,
    logout,
    hydrate,
    changePassword,
    refreshMe,
  };
}
