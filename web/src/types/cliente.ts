import type { TipoFactura } from './factura';

export interface Cliente {
  id: number;
  razon_social: string;
  cuit: string;
  cuit_pais: string | null;
  tipo_default: TipoFactura | null;
  direccion: string | null;
  banco: string | null;
  cbu: string | null;
  alias: string | null;
  plazo_pago_default: number | null;
  mail_envio_factura: string | null;
  contacto_envio_factura: string | null;
  telefono_contacto_proveedores: string | null;
  mail_gestion_cobranza: string | null;
  contacto_gestion_cobranza: string | null;
  telefono_contacto_cobranza: string | null;
  observaciones: string | null;
  activo: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}
