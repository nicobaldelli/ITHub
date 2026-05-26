'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Servicio, TipoServicio, EstadoServicio } from '@/types/servicio';
import type { Moneda } from '@/types/factura';

export interface ServiciosFilters {
  search?: string;
  cliente_id?: number | '';
  tipo?: TipoServicio | '';
  estado?: EstadoServicio | '';
  moneda?: Moneda | '';
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export function useServicios(filters: ServiciosFilters) {
  const [data, setData] = useState<Servicio[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    const params = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => {
      if (v === undefined || v === '' || v === false) return;
      params.set(k, String(v));
    });

    api
      .get<ApiSuccess<Servicio[]>>(`/servicios?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando servicios')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [JSON.stringify(filters)]);

  return { data, meta, loading, error };
}
