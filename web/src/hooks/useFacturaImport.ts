'use client';

import { useCallback } from 'react';
import { api } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type { ImportPreview, ImportResultado } from '@/types/import';

/** Descarga un blob de la API como archivo (misma lógica que los exports). */
async function descargar(url: string, fallbackName: string): Promise<void> {
  const res = await api.get(url, { responseType: 'blob' });
  const blob = res.data as Blob;
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  const dispo = res.headers['content-disposition'] ?? '';
  const m = /filename="?([^"]+)"?/.exec(dispo);
  a.download = m ? m[1] : fallbackName;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(href);
}

export function useFacturaImport() {
  const descargarPlantilla = useCallback(
    () => descargar('/facturas/import/plantilla', 'ithub-plantilla-import-facturas.xlsx'),
    [],
  );

  const preview = useCallback(async (archivo: File): Promise<ImportPreview> => {
    const form = new FormData();
    form.append('archivo', archivo);
    const res = await api.post<ApiSuccess<ImportPreview>>('/facturas/import/preview', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      // Archivos grandes + validación fila por fila: más margen que el default
      timeout: 120_000,
    });
    return res.data.data;
  }, []);

  const confirm = useCallback(
    async (token: string, omitirConError: boolean): Promise<ImportResultado> => {
      const res = await api.post<ApiSuccess<ImportResultado>>(
        '/facturas/import/confirm',
        { token, omitir_con_error: omitirConError },
        { timeout: 120_000 },
      );
      return res.data.data;
    },
    [],
  );

  return { descargarPlantilla, preview, confirm };
}
