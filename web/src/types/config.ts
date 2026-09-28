export type ConfigTipo = 'string' | 'int' | 'bool' | 'json';

export interface ConfigEntry {
  clave: string;
  valor: string | null;
  /** Valor ya casteado al tipo correspondiente. */
  value_parsed: string | number | boolean | unknown[] | Record<string, unknown> | null;
  tipo: ConfigTipo;
  descripcion: string | null;
  /** Clave que el API nunca devuelve en claro (ej: smtp_pass); `valor` llega null. */
  sensible: boolean;
  /** Para claves sensibles: indica si hay un valor cargado aunque no se muestre. */
  configurado: boolean;
  updated_by: number | null;
  updated_at: string | null;
}
