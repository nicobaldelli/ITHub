'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Factura } from '@/types/factura';

export interface FacturasFilters {
  search?: string;
  cliente_id?: number | '';
  tipo?: string;
  moneda?: 'ARS' | 'USD' | '';
  estado?: string;
  fecha_desde?: string;
  fecha_hasta?: string;
  cobrado?: '' | 'true' | 'false';
  vencidas?: boolean;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export function useFacturas(filters: FacturasFilters) {
  const [data, setData] = useState<Factura[]>([]);
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
      .get<ApiSuccess<Factura[]>>(`/facturas?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando facturas')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [JSON.stringify(filters)]); // simple, recalcula al cambiar filtros

  return { data, meta, loading, error };
}
