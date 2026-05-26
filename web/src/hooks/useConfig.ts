'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type { ConfigEntry } from '@/types/config';

export function useConfig() {
  const [data, setData] = useState<ConfigEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<ConfigEntry[]>>('/config')
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando configuración')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  const update = useCallback(
    async (clave: string, valor: string | boolean | number | unknown[] | Record<string, unknown>) => {
      const res = await api.put<ApiSuccess<ConfigEntry>>(`/config/${clave}`, { valor });
      return res.data.data;
    },
    [],
  );

  return { data, loading, error, reload, update };
}
