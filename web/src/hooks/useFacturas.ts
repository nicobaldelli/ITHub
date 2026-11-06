'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Factura } from '@/types/factura';

export type FiltroBool = '' | 'true' | 'false';

export interface FacturasFilters {
  search?: string;
  cliente_id?: number | '';
  tipo?: string;
  moneda?: 'ARS' | 'USD' | '';
  estado?: string;
  numero_factura?: string;
  cuit?: string;
  detalle?: string;
  observaciones?: string;
  mes_cubierto?: string;
  numero_mes?: string;
  banco?: string;
  mail?: string;
  contacto?: string;
  created_by?: number | '';
  origen?: '' | 'servicio' | 'manual';
  fecha_desde?: string;
  fecha_hasta?: string;
  vencimiento_desde?: string;
  vencimiento_hasta?: string;
  pago_desde?: string;
  pago_hasta?: string;
  envio_desde?: string;
  envio_hasta?: string;
  creada_desde?: string;
  creada_hasta?: string;
  importe_min?: string;
  importe_max?: string;
  importe_con_iva_min?: string;
  importe_con_iva_max?: string;
  cobrado_min?: string;
  cobrado_max?: string;
  plazo_desde?: string;
  plazo_hasta?: string;
  cobrado?: FiltroBool;
  con_retenciones?: FiltroBool;
  con_pago?: FiltroBool;
  enviada?: FiltroBool;
  con_tdc?: FiltroBool;
  vencidas?: boolean;
  por_vencer_dias?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export function buildFacturasQuery(filters: FacturasFilters): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([k, v]) => {
    if (v === undefined || v === '' || v === false || v === null) return;
    params.set(k, String(v));
  });
  return params.toString();
}

export function useFacturas(filters: FacturasFilters) {
  const [data, setData] = useState<Factura[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // La query string se calcula fuera del efecto: es un string, así que sirve
  // como dependencia estable aunque el objeto `filters` cambie de identidad.
  const query = buildFacturasQuery(filters);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Factura[]>>(`/facturas?${query}`)
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
  }, [query]);

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
      pdf: File,
    ): Promise<Factura> => {
      const form = new FormData();
      form.append('archivo', pdf);
      form.append('numero_factura', data.numero_factura);
      form.append('fecha_factura', data.fecha_factura);
      form.append('fecha_envio', data.fecha_envio);
      if (data.tdc !== undefined && data.tdc !== null) {
        form.append('tdc', String(data.tdc));
      }
      // POST y no PATCH: PHP solo parsea multipart (el PDF) en POST
      const res = await api.post<ApiSuccess<Factura>>(
        `/facturas/${id}/marcar-enviada`,
        form,
        { headers: { 'Content-Type': 'multipart/form-data' } },
      );
      return res.data.data;
    },
    [],
  );

  return { create, update, remove, toggleCheckCobranza, marcarEnviada };
}
