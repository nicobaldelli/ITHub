'use client';

import { useCallback, useEffect, useState } from 'react';
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

/** Carga un servicio con sus cuotas y ajustes. */
export function useServicio(id: number | null) {
  const [data, setData] = useState<Servicio | null>(null);
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
      .get<ApiSuccess<Servicio>>(`/servicios/${id}`)
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando servicio')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [id, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  return { data, loading, error, reload };
}

export function useServicioMutations() {
  const create = useCallback(async (data: unknown): Promise<Servicio> => {
    const res = await api.post<ApiSuccess<Servicio>>('/servicios', data);
    return res.data.data;
  }, []);

  const update = useCallback(async (id: number, data: unknown): Promise<Servicio> => {
    const res = await api.put<ApiSuccess<Servicio>>(`/servicios/${id}`, data);
    return res.data.data;
  }, []);

  const remove = useCallback(async (id: number): Promise<void> => {
    await api.delete(`/servicios/${id}`);
  }, []);

  // ----- Acciones de estado del servicio -----
  const pausar = useCallback(async (id: number): Promise<Servicio> => {
    const res = await api.patch<ApiSuccess<Servicio>>(`/servicios/${id}/pausar`);
    return res.data.data;
  }, []);

  const reanudar = useCallback(
    async (id: number, modo: 'cancelar_pasadas' | 'correr_cronograma'): Promise<Servicio> => {
      const res = await api.patch<ApiSuccess<Servicio>>(`/servicios/${id}/reanudar`, { modo });
      return res.data.data;
    },
    [],
  );

  const cancelar = useCallback(async (id: number): Promise<Servicio> => {
    const res = await api.patch<ApiSuccess<Servicio>>(`/servicios/${id}/cancelar`);
    return res.data.data;
  }, []);

  const extender = useCallback(
    async (
      id: number,
      data: { meses?: number; nueva_fecha_fin?: string; nuevo_importe_base?: number },
    ): Promise<Servicio> => {
      const res = await api.post<ApiSuccess<Servicio>>(`/servicios/${id}/extender`, data);
      return res.data.data;
    },
    [],
  );

  // ----- Acciones sobre cuotas -----
  const omitirCuota = useCallback(async (servicioId: number, cuotaId: number): Promise<void> => {
    await api.patch(`/servicios/${servicioId}/cuotas/${cuotaId}/omitir`);
  }, []);

  const cancelarCuota = useCallback(
    async (servicioId: number, cuotaId: number): Promise<void> => {
      await api.patch(`/servicios/${servicioId}/cuotas/${cuotaId}/cancelar`);
    },
    [],
  );

  const facturarCuota = useCallback(
    async (
      servicioId: number,
      cuotaId: number,
      data: {
        numero_factura: string;
        tipo: string;
        tdc?: number | null;
        fecha_factura?: string;
        vencimiento?: string;
        detalle_factura?: string;
      },
    ) => {
      const res = await api.post<ApiSuccess<unknown>>(
        `/servicios/${servicioId}/cuotas/${cuotaId}/facturar`,
        data,
      );
      return res.data.data;
    },
    [],
  );

  // ----- Ajustes -----
  const crearAjuste = useCallback(
    async (
      servicioId: number,
      data: {
        tipo: 'programado' | 'espontaneo';
        modo: 'monto' | 'porcentaje';
        valor: number;
        fecha_aplicacion: string;
        cuota_desde_id?: number | null;
        observaciones?: string | null;
      },
    ) => {
      const res = await api.post<ApiSuccess<unknown>>(
        `/servicios/${servicioId}/ajustes`,
        data,
      );
      return res.data.data;
    },
    [],
  );

  const aplicarAjuste = useCallback(
    async (servicioId: number, ajusteId: number) => {
      const res = await api.post<ApiSuccess<unknown>>(
        `/servicios/${servicioId}/ajustes/${ajusteId}/aplicar`,
      );
      return res.data.data;
    },
    [],
  );

  const eliminarAjuste = useCallback(
    async (servicioId: number, ajusteId: number): Promise<void> => {
      await api.delete(`/servicios/${servicioId}/ajustes/${ajusteId}`);
    },
    [],
  );

  return {
    create,
    update,
    remove,
    pausar,
    reanudar,
    cancelar,
    extender,
    omitirCuota,
    cancelarCuota,
    facturarCuota,
    crearAjuste,
    aplicarAjuste,
    eliminarAjuste,
  };
}
