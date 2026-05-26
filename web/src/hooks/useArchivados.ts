'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';

export type EntidadArchivable = 'clientes' | 'facturas' | 'servicios';

export interface ArchivadosFilters {
  entidad: EntidadArchivable;
  search?: string;
  page?: number;
  per_page?: number;
}

export function useArchivados(filters: ArchivadosFilters) {
  const [data, setData] = useState<Array<Record<string, unknown>>>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    const params = new URLSearchParams({ entidad: filters.entidad });
    if (filters.search) params.set('search', filters.search);
    if (filters.page) params.set('page', String(filters.page));
    if (filters.per_page) params.set('per_page', String(filters.per_page));

    api
      .get<ApiSuccess<Array<Record<string, unknown>>>>(`/archivados?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando archivados')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [filters.entidad, filters.search, filters.page, filters.per_page, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  const restaurar = useCallback(async (entidad: EntidadArchivable, id: number) => {
    await api.post(`/archivados/${entidad}/${id}/restaurar`);
  }, []);

  return { data, meta, loading, error, reload, restaurar };
}
