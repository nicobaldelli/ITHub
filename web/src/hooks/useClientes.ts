'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess, ApiMeta } from '@/types/api';
import type { Cliente } from '@/types/cliente';
import type { Factura } from '@/types/factura';
import type { ClienteFormData } from '@/lib/cliente-schema';

export interface ClientesFilters {
  search?: string;
  razon_social?: string;
  cuit?: string;
  cuit_pais?: string;
  tipo_default?: string;
  direccion?: string;
  banco?: string;
  alias?: string;
  cbu?: string;
  mail?: string;
  contacto?: string;
  telefono?: string;
  observaciones?: string;
  plazo_desde?: string;
  plazo_hasta?: string;
  activo?: boolean | '';
  con_facturas?: '' | 'true' | 'false';
  alta_desde?: string;
  alta_hasta?: string;
  modificado_desde?: string;
  modificado_hasta?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

/** Query string a partir de los filtros; omite vacíos para no mandar ruido al backend. */
export function buildClientesQuery(filters: ClientesFilters): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([k, v]) => {
    if (v === undefined || v === '' || v === null) return;
    if (k === 'activo') {
      params.set('activo', v ? 'true' : 'false');
      return;
    }
    params.set(k, String(v));
  });
  return params.toString();
}

export function useClientes(filters: ClientesFilters) {
  const [data, setData] = useState<Cliente[]>([]);
  const [meta, setMeta] = useState<ApiMeta>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // La query string se calcula fuera del efecto: es un string, así que sirve
  // como dependencia estable aunque el objeto `filters` cambie de identidad.
  const query = buildClientesQuery(filters);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Cliente[]>>(`/clientes?${query}`)
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
  }, [query]);

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

/**
 * Carga TODOS los clientes (activos e inactivos) para selects de filtros,
 * ordenados por razón social. Sin paginación.
 */
export function useClientesTodos() {
  const [data, setData] = useState<Cliente[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<Cliente[]>>('/clientes?per_page=500&sort_by=razon_social&sort_dir=asc')
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
