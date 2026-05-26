import type { ClienteResumen, Moneda } from './factura';

export type TipoServicio = 'proyecto' | 'mantenimiento';
export type EstadoServicio = 'activo' | 'pausado' | 'completado' | 'cancelado';
export type ModoFacturacion = 'mes_calendario' | 'intervalo_dias';
export type EstadoCuota = 'pendiente' | 'facturada' | 'omitida' | 'cancelada';
export type TipoAjuste = 'programado' | 'espontaneo';

export interface Servicio {
  id: number;
  cliente_id: number;
  cliente?: ClienteResumen;
  tipo: TipoServicio;
  nombre: string;
  descripcion: string | null;
  moneda: Moneda;
  importe_base: string;
  /** Alícuota de IVA: 0, 10.5 o 21 */
  iva_porcentaje: string | number;
  /** Template del detalle que se aplica al facturar cada cuota. Soporta placeholders. */
  template_factura: string | null;
  fecha_inicio: string;
  fecha_fin: string | null;
  modo_facturacion: ModoFacturacion | null;
  dia_facturacion: number | null;
  intervalo_dias: number | null;
  frecuencia_ajuste_meses: number | null;
  aviso_dias_previos: number | null;
  estado: EstadoServicio;
  pausado_at: string | null;
  observaciones: string | null;
  created_by: number;
  updated_by: number;
  created_at: string;
  updated_at: string;
  cuotas?: ServicioCuota[];
  ajustes?: ServicioAjuste[];
}

export interface ServicioCuota {
  id: number;
  servicio_id: number;
  numero_cuota: number;
  total_cuotas: number | null;
  porcentaje: string | null;
  importe: string;
  fecha_prevista: string;
  factura_id: number | null;
  estado: EstadoCuota;
  etiqueta: string | null;
  es_proporcional: boolean;
  dias_cubiertos: number | null;
  observaciones: string | null;
  created_at: string;
  updated_at: string;
}

export interface ServicioAjuste {
  id: number;
  servicio_id: number;
  tipo: TipoAjuste;
  fecha_aplicacion: string;
  cuota_desde_id: number | null;
  importe_anterior: string;
  importe_nuevo: string;
  porcentaje_variacion: string | null;
  aplicado: boolean;
  aplicado_at: string | null;
  aplicado_por: number | null;
  observaciones: string | null;
  created_by: number;
  created_at: string;
  updated_at: string;
}
