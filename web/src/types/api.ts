// Respuestas estandar de la API
export interface ApiSuccess<T> {
  data: T;
  meta?: ApiMeta;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
    request_id?: string;
  };
}

export interface ApiMeta {
  page?: number;
  per_page?: number;
  total?: number;
  total_pages?: number;
}

export type Rol = 'admin' | 'cobranzas' | 'ventas' | 'visualizador';

export interface User {
  id: number;
  nombre: string;
  apellido: string;
  email: string;
  rol: Rol;
  activo: boolean;
  last_login: string | null;
}

export interface LoginResponse {
  user: User;
  access_token: string;
  access_expires_at: number;
  token_type: 'Bearer';
  csrf_token: string;
}

export interface RefreshResponse {
  user: User;
  access_token: string;
  access_expires_at: number;
  token_type: 'Bearer';
}
