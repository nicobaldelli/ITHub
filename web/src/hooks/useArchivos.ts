'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type { ArchivosResponse, FacturaArchivo } from '@/types/archivo';

export function useArchivos(facturaId: number | null) {
  const [data, setData] = useState<FacturaArchivo[]>([]);
  const [driveDisponible, setDriveDisponible] = useState(true);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (!facturaId) {
      setLoading(false);
      return;
    }
    let canceled = false;
    setLoading(true);
    setError(null);

    api
      .get<ApiSuccess<ArchivosResponse>>(`/facturas/${facturaId}/archivos`)
      .then((res) => {
        if (canceled) return;
        setData(res.data.data.archivos);
        setDriveDisponible(res.data.data.drive_disponible);
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando adjuntos')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [facturaId, reloadKey]);

  const reload = useCallback(() => setReloadKey((k) => k + 1), []);

  const upload = useCallback(
    async (id: number, file: File): Promise<FacturaArchivo> => {
      const form = new FormData();
      form.append('archivo', file);
      const res = await api.post<ApiSuccess<FacturaArchivo>>(
        `/facturas/${id}/archivos`,
        form,
        { headers: { 'Content-Type': 'multipart/form-data' } },
      );
      return res.data.data;
    },
    [],
  );

  const remove = useCallback(async (id: number, archivoId: number): Promise<void> => {
    await api.delete(`/facturas/${id}/archivos/${archivoId}`);
  }, []);

  return { data, driveDisponible, loading, error, reload, upload, remove };
}
