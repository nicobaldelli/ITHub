'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Cliente } from '@/types/cliente';
import type { Factura } from '@/types/factura';
import type { ClienteFormData } from '@/lib/cliente-schema';

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

/** Carga un cliente por ID. Devuelve null si todavía no se cargó o si el id es 0. */
export function useCliente(id: number | null) {
  const [data, setData] = useState<Cliente | null>(null);
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
      .get<ApiSuccess<Cliente>>(`/clientes/${id}`)
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando cliente')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [id]);

  return { data, loading, error };
}

/** Carga las facturas del cliente (endpoint dedicado del backend). */
export function useFacturasDeCliente(id: number | null) {
  const [data, setData] = useState<Factura[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!id) {
      setData([]);
      setLoading(false);
      return;
    }
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Factura[]>>(`/clientes/${id}/facturas?per_page=10`)
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando facturas del cliente')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [id]);

  return { data, loading, error };
}

/** Carga TODOS los clientes activos para selects/dropdowns (sin paginación). */
export function useClientesActivos() {
  const [data, setData] = useState<Cliente[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Cliente[]>>('/clientes?activo=true&per_page=500&sort_by=razon_social&sort_dir=asc')
      .then((res) => !canceled && setData(res.data.data))
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando clientes')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, []);

  return { data, loading, error };
}

export function useClienteMutations() {
  const create = useCallback(async (data: ClienteFormData): Promise<Cliente> => {
    const res = await api.post<ApiSuccess<Cliente>>('/clientes', data);
    return res.data.data;
  }, []);

  const update = useCallback(async (id: number, data: ClienteFormData): Promise<Cliente> => {
    const res = await api.put<ApiSuccess<Cliente>>(`/clientes/${id}`, data);
    return res.data.data;
  }, []);

  const remove = useCallback(async (id: number): Promise<void> => {
    await api.delete(`/clientes/${id}`);
  }, []);

  return { create, update, remove };
}
