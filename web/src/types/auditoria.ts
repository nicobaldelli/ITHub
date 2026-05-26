export type AuditoriaAccion =
  | 'crear'
  | 'editar'
  | 'eliminar'
  | 'marcar_cobrada'
  | 'login'
  | 'login_fallido'
  | 'logout'
  | 'export'
  | 'import'
  | 'archivo_subido'
  | 'archivo_eliminado'
  | 'config_actualizada'
  | 'cambio_password'
  | 'reset_password';

export interface AuditoriaEntry {
  id: number;
  user_id: number | null;
  user: {
    id: number;
    nombre: string;
    apellido: string;
    email: string;
  } | null;
  entidad: string;
  entidad_id: number | null;
  accion: AuditoriaAccion;
  campos_modificados: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  request_id: string | null;
  created_at: string;
}
