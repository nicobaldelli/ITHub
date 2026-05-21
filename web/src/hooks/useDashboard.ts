'use client';

import { useEffect, useState } from 'react';
import { api, apiErrorMessage } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';
import type {
  DashboardKpis,
  TendenciaPunto,
  AgingBuckets,
  TopCliente,
  DistribucionTipo,
  DistribucionMoneda,
} from '@/types/dashboard';

export type Periodo = 'mes_actual' | 'mes_anterior' | 'trimestre' | 'anio';

interface DashboardData {
  kpis: DashboardKpis | null;
  tendencias: TendenciaPunto[];
  aging: AgingBuckets | null;
  topClientes: TopCliente[];
  distribucionTipo: DistribucionTipo[];
  distribucionMoneda: DistribucionMoneda[];
}

export function useDashboard(periodo: Periodo) {
  const [data, setData] = useState<DashboardData>({
    kpis: null,
    tendencias: [],
    aging: null,
    topClientes: [],
    distribucionTipo: [],
    distribucionMoneda: [],
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    setError(null);

    Promise.all([
      api.get<ApiSuccess<DashboardKpis>>(`/dashboard/kpis?periodo=${periodo}`),
      api.get<ApiSuccess<TendenciaPunto[]>>(`/dashboard/tendencias?meses=12`),
      api.get<ApiSuccess<AgingBuckets>>(`/dashboard/aging`),
      api.get<ApiSuccess<TopCliente[]>>(`/dashboard/top-clientes?periodo=${periodo}&limit=10`),
      api.get<ApiSuccess<DistribucionTipo[] | DistribucionTipo>>(
        `/dashboard/distribucion-tipo?periodo=${periodo}`,
      ),
      api.get<ApiSuccess<DistribucionMoneda[]>>(`/dashboard/distribucion-moneda?periodo=${periodo}`),
    ])
      .then(([kpi, ten, ag, top, dt, dm]) => {
        if (canceled) return;
        // El backend devuelve objeto cuando hay un único elemento; normalizamos a array.
        const distTipoRaw = dt.data.data;
        const distTipo = Array.isArray(distTipoRaw) ? distTipoRaw : [distTipoRaw];
        setData({
          kpis: kpi.data.data,
          tendencias: ten.data.data,
          aging: ag.data.data,
          topClientes: top.data.data,
          distribucionTipo: distTipo,
          distribucionMoneda: dm.data.data,
        });
      })
      .catch((e) => !canceled && setError(apiErrorMessage(e, 'Error cargando dashboard')))
      .finally(() => !canceled && setLoading(false));

    return () => {
      canceled = true;
    };
  }, [periodo]);

  return { data, loading, error };
}
