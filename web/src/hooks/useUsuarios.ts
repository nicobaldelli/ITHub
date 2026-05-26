'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta, Rol } from '@/types/api';
import type { Usuario, UsuarioConPassword } from '@/types/usuario';
import type { UsuarioCreateData, UsuarioUpdateData } from '@/lib/usuario-schema';

export interface UsuariosFilters {
  search?: string;
  rol?: Rol | '';
  activo?: boolean | '';
  page?: number;
  per_page?: number;
}

export function useUsuarios(filters: UsuariosFilters) {
  const [data, setData] = useState<Usuario[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.rol) params.set('rol', filters.rol);
    if (filters.activo !== undefined && filters.activo !== '') {
      params.set('activo', filters.activo ? 'true' : 'false');
    }
    if (filters.page) params.set('page', String(filters.page));
    if (filters.per_page) params.set('per_page', String(filters.per_page));

    api
      .get<ApiSuccess<Usuario[]>>(`/usuarios?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando usuarios')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [filters.search, filters.rol, filters.activo, filters.page, filters.per_page, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);
  return { data, meta, loading, error, reload };
}

export function useUsuario(id: number | null) {
  const [data, setData] = useState<Usuario | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!id) {
      setData(null);
      setLoading(false);
      return;
    }
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Usuario>>(`/usuarios/${id}`)
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando usuario')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [id]);

  return { data, loading, error };
}

export function useUsuarioMutations() {
  const create = useCallback(async (data: UsuarioCreateData): Promise<UsuarioConPassword> => {
    const payload: Record<string, unknown> = {
      nombre: data.nombre,
      apellido: data.apellido,
      email: data.email,
      rol: data.rol,
      activo: data.activo ?? true,
    };
    if (data.password && data.password.trim() !== '') {
      payload.password = data.password;
    }
    const res = await api.post<ApiSuccess<UsuarioConPassword>>('/usuarios', payload);
    return res.data.data;
  }, []);

  const update = useCallback(async (id: number, data: UsuarioUpdateData): Promise<Usuario> => {
    const res = await api.put<ApiSuccess<Usuario>>(`/usuarios/${id}`, data);
    return res.data.data;
  }, []);

  const resetPassword = useCallback(async (id: number): Promise<UsuarioConPassword> => {
    const res = await api.post<ApiSuccess<UsuarioConPassword>>(`/usuarios/${id}/reset-password`);
    return res.data.data;
  }, []);

  const desactivar = useCallback(async (id: number): Promise<Usuario> => {
    const res = await api.delete<ApiSuccess<Usuario>>(`/usuarios/${id}`);
    return res.data.data;
  }, []);

  const activar = useCallback(async (id: number): Promise<Usuario> => {
    const res = await api.patch<ApiSuccess<Usuario>>(`/usuarios/${id}/activar`);
    return res.data.data;
  }, []);

  return { create, update, resetPassword, desactivar, activar };
}
