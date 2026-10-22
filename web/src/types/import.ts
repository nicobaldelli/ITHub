import type { EstadoFactura } from './factura';

/** Una fila del archivo tal como la devuelve POST /facturas/import/preview. */
export interface ImportFila {
  /** Número de fila en el archivo (1 = cabecera). */
  n: number;
  datos: {
    numero_factura: string;
    cliente_id: number;
    tipo: string;
    cuit: string;
    moneda: string;
    fecha_factura: string;
    vencimiento: string | null;
    fecha_pago: string | null;
    importe_sin_iva: number | string | null;
    importe_con_iva: number | string | null;
    importe_total_pesos: number | string | null;
    tdc: number | string | null;
    [k: string]: unknown;
  };
  cobrada: boolean;
  /** Razón social del cliente resuelto por CUIT (null si no se encontró). */
  cliente: string | null;
  estado_previsto: EstadoFactura;
  /** campo -> mensaje. Vacío si la fila es válida. */
  errores: Record<string, string>;
}

export interface ImportPreview {
  token: string;
  resumen: { total: number; validas: number; con_error: number };
  columnas_ignoradas: string[];
  filas: ImportFila[];
}

export interface ImportResultado {
  importadas: number;
  omitidas: number;
  ids: number[];
}
