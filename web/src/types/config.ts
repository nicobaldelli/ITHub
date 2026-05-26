export type ConfigTipo = 'string' | 'int' | 'bool' | 'json';

export interface ConfigEntry {
  clave: string;
  valor: string | null;
  /** Valor ya casteado al tipo correspondiente. */
  value_parsed: string | number | boolean | unknown[] | Record<string, unknown> | null;
  tipo: ConfigTipo;
  descripcion: string | null;
  updated_by: number | null;
  updated_at: string | null;
}
