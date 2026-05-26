export type TipoFactura =
  | 'A'
  | 'B'
  | 'E'
  | 'CREDITO_MIPYME_A'
  | 'CREDITO_MIPYME_B'
  | 'NC_A'
  | 'NC_B'
  | 'NC_E'
  | 'ND_A'
  | 'ND_B'
  | 'ND_E';

export type Moneda = 'ARS' | 'USD';

export type EstadoFactura = 'borrador' | 'emitida' | 'cobrada' | 'vencida' | 'anulada';

export interface ClienteResumen {
  id: number;
  razon_social: string;
  cuit: string;
}

export interface Factura {
  id: number;
  numero_factura: string;
  cliente_id: number;
  cliente?: ClienteResumen;
  tipo: TipoFactura;
  cuit: string;
  cuit_pais?: string | null;
  moneda: Moneda;
  importe_sin_iva: string;
  importe_con_iva: string;
  importe_total_pesos: string;
  tdc: string | null;
  retenciones: string;
  total_cobrado: string;
  detalle_factura: string | null;
  numero_mes: number | null;
  mes_cubierto: string | null;
  fecha_factura: string;
  fecha_envio: string | null;
  banco: string | null;
  vencimiento: string | null;
  cbu: string | null;
  alias: string | null;
  plazo_pago: number | null;
  fecha_pago: string | null;
  direccion: string | null;
  mail_envio_factura: string | null;
  contacto_envio_factura: string | null;
  telefono_contacto_proveedores: string | null;
  mail_gestion_cobranza: string | null;
  contacto_gestion_cobranza: string | null;
  telefono_contacto_cobranza: string | null;
  observaciones: string | null;
  check_cobranza: boolean;
  check_cobranza_user_id: number | null;
  check_cobranza_fecha: string | null;
  drive_folder_id: string | null;
  servicio_cuota_id: number | null;
  estado: EstadoFactura;
  created_by: number;
  updated_by: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}
