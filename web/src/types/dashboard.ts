export interface DashboardKpis {
  periodo: { desde: string; hasta: string };
  total_facturado: number;
  total_facturado_usd_equivalente: number | null;
  tdc_promedio_usd: number | null;
  total_cobrado: number;
  pendiente: number;
  vencidas: { cantidad: number; monto: number };
  tasa_recuperacion_pct: number | null;
  tasa_recuperacion_semaforo: 'verde' | 'amarillo' | 'rojo' | 'unknown';
  dso_dias: number | null;
  add_dias: number | null;
}

export interface TendenciaPunto {
  periodo: string; // YYYY-MM
  facturado: number;
  cobrado: number;
}

export type AgingBuckets = Record<
  '0_30' | '31_60' | '61_90' | '91_plus',
  { cantidad: number; monto: number }
>;

export interface TopCliente {
  cliente_id: number;
  razon_social: string;
  facturado: number;
  cobrado: number;
  cantidad_facturas: number;
}

export interface DistribucionTipo {
  tipo: string;
  cantidad: number;
  monto: number;
}

export interface DistribucionMoneda {
  moneda: 'ARS' | 'USD';
  cantidad: number;
  monto_pesos: number;
}
