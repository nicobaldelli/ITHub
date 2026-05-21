'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Cliente } from '@/types/cliente';

export interface ClientesFilters {
  search?: string;
  activo?: boolean | '';
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export function useClientes(filters: ClientesFilters) {
  const [data, setData] = useState<Cliente[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.activo !== undefined && filters.activo !== '') {
      params.set('activo', filters.activo ? 'true' : 'false');
    }
    if (filters.page) params.set('page', String(filters.page));
    if (filters.per_page) params.set('per_page', String(filters.per_page));
    if (filters.sort_by) params.set('sort_by', filters.sort_by);
    if (filters.sort_dir) params.set('sort_dir', filters.sort_dir);

    api
      .get<ApiSuccess<Cliente[]>>(`/clientes?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando clientes')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [
    filters.search,
    filters.activo,
    filters.page,
    filters.per_page,
    filters.sort_by,
    filters.sort_dir,
  ]);

  return { data, meta, loading, error };
}
