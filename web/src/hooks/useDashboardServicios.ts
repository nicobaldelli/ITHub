'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type {
  ServiciosActivosData,
  CuotasMesData,
  AjustesProximosData,
  MrrData,
} from '@/types/dashboard-servicios';

interface State {
  serviciosActivos: ServiciosActivosData | null;
  cuotasMes: CuotasMesData | null;
  ajustesProximos: AjustesProximosData | null;
  mrr: MrrData | null;
}

export function useDashboardServicios() {
  const [data, setData] = useState<State>({
    serviciosActivos: null,
    cuotasMes: null,
    ajustesProximos: null,
    mrr: null,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    Promise.all([
      api.get<ApiSuccess<ServiciosActivosData>>('/dashboard/servicios-activos'),
      api.get<ApiSuccess<CuotasMesData>>('/dashboard/cuotas-mes'),
      api.get<ApiSuccess<AjustesProximosData>>('/dashboard/ajustes-proximos'),
      api.get<ApiSuccess<MrrData>>('/dashboard/mrr'),
    ])
      .then(([sa, cm, ap, mr]) => {
        if (canceled) return;
        setData({
          serviciosActivos: sa.data.data,
          cuotasMes: cm.data.data,
          ajustesProximos: ap.data.data,
          mrr: mr.data.data,
        });
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando dashboard de servicios')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, []);

  return { data, loading, error };
}
