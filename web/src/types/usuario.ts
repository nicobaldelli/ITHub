import type { Rol } from './api';

export interface Usuario {
  id: number;
  nombre: string;
  apellido: string;
  email: string;
  rol: Rol;
  activo: boolean;
  last_login: string | null;
  last_login_ip: string | null;
  created_at: string;
  updated_at: string;
}
