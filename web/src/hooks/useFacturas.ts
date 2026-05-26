'use client';

import { useCallback, useEffect, useState } from 'react';
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

/** Carga una factura por ID. */
export function useFactura(id: number | null) {
  const [data, setData] = useState<Factura | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

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
      .get<ApiSuccess<Factura>>(`/facturas/${id}`)
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando factura')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [id, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);
  return { data, loading, error, reload };
}

export function useFacturaMutations() {
  const create = useCallback(async (data: unknown): Promise<Factura> => {
    const res = await api.post<ApiSuccess<Factura>>('/facturas', data);
    return res.data.data;
  }, []);

  const update = useCallback(async (id: number, data: unknown): Promise<Factura> => {
    const res = await api.put<ApiSuccess<Factura>>(`/facturas/${id}`, data);
    return res.data.data;
  }, []);

  const remove = useCallback(async (id: number): Promise<void> => {
    await api.delete(`/facturas/${id}`);
  }, []);

  const toggleCheckCobranza = useCallback(async (id: number): Promise<Factura> => {
    const res = await api.patch<ApiSuccess<Factura>>(`/facturas/${id}/check-cobranza`);
    return res.data.data;
  }, []);

  const marcarEnviada = useCallback(
    async (
      id: number,
      data: { numero_factura: string; fecha_factura: string; fecha_envio: string; tdc?: number | null },
    ): Promise<Factura> => {
      const res = await api.patch<ApiSuccess<Factura>>(`/facturas/${id}/marcar-enviada`, data);
      return res.data.data;
    },
    [],
  );

  return { create, update, remove, toggleCheckCobranza, marcarEnviada };
}
