'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { AuditoriaEntry, AuditoriaAccion } from '@/types/auditoria';

export interface AuditoriaFilters {
  entidad?: string;
  entidad_id?: number | '';
  accion?: AuditoriaAccion | '';
  user_id?: number | '';
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export function useAuditoria(filters: AuditoriaFilters) {
  const [data, setData] = useState<AuditoriaEntry[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    const params = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => {
      if (v === undefined || v === '' || v === null) return;
      params.set(k, String(v));
    });

    api
      .get<ApiSuccess<AuditoriaEntry[]>>(`/auditoria?${params.toString()}`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data);
        setMeta(res.data.meta ?? {});
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando auditoría')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [JSON.stringify(filters)]);

  return { data, meta, loading, error };
}
