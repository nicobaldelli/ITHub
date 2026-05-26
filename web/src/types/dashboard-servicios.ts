import type { Moneda } from './factura';
import type { TipoServicio, TipoAjuste } from './servicio';

export interface ServiciosActivosData {
  proyecto_activos: number;
  proyecto_pausados: number;
  mantenimiento_activos: number;
  mantenimiento_pausados: number;
  indefinidos: number;
  total: number;
}

export interface CuotaDelMes {
  id: number;
  servicio_id: number;
  servicio_nombre: string;
  servicio_tipo: TipoServicio;
  cliente_id: number;
  razon_social: string;
  numero_cuota: number;
  etiqueta: string | null;
  fecha_prevista: string;
  importe: number;
  moneda: Moneda;
}

export interface CuotasMesData {
  periodo: { desde: string; hasta: string };
  total_por_moneda: { ARS: number; USD: number };
  cantidad_por_moneda: { ARS: number; USD: number };
  cantidad_total: number;
  cuotas: CuotaDelMes[];
}

export interface AjusteProximo {
  id: number;
  servicio_id: number;
  servicio_nombre: string;
  cliente_id: number;
  razon_social: string;
  tipo: TipoAjuste;
  fecha_aplicacion: string;
  importe_anterior: number;
  importe_nuevo: number;
  porcentaje_variacion: number | null;
  moneda: Moneda;
}

export interface AjustesProximosData {
  ventana: { desde: string; hasta: string; dias: number };
  cantidad: number;
  ajustes: AjusteProximo[];
}

export interface MrrData {
  mrr_por_moneda: { ARS: number; USD: number };
  arr_por_moneda: { ARS: number; USD: number };
  cantidad_servicios: { ARS: number; USD: number };
  cantidad_total: number;
  criterios: {
    estados_incluidos: string[];
    dias_normalizacion: number;
    incluye_consolidado_ars: boolean;
  };
}
